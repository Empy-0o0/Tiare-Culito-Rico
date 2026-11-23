<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicProcurement\CompraAgilIndexRequest;
use App\Http\Resources\CompraAgilResource;
use App\Models\PublicProcurementOpportunity;
use App\Services\MercadoPublico\CompraAgilDetailService;
use App\Services\MercadoPublico\DTO\CompraAgilSearchQuery;
use App\Services\MercadoPublico\Sync\MercadoPublicoSyncService;
use App\Models\MercadoPublicoSyncState;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monitor de Compra Ágil. Lee la base local sincronizada desde la API oficial V2;
 * el detalle se refresca bajo demanda. React nunca llama a mercadopublico.cl.
 */
class CompraAgilController extends Controller
{
    public function index(CompraAgilIndexRequest $request): JsonResponse
    {
        $v = $request->validated();
        $sort = $v['sort'] ?? 'source_updated_at';
        $direction = $v['direction'] ?? 'desc';

        $query = PublicProcurementOpportunity::query()
            ->where('source_system', PublicProcurementOpportunity::SOURCE_SYSTEM)
            ->where('source_type', PublicProcurementOpportunity::TYPE_COMPRA_AGIL)
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)
                    ->orWhere('external_code', 'like', $like)
                    ->orWhere('buyer_name', 'like', $like)
                    ->orWhere('description', 'like', $like));
            })
            ->when($v['status'] ?? null, fn ($q, array $s) => $q->whereIn('status_code', $s))
            ->when($v['region'] ?? null, fn ($q, array $r) => $q->whereIn('region_code', array_map('intval', $r)))
            ->when($v['buyer_rut'] ?? null, fn ($q, string $rut) => $q->where('buyer_rut', $rut))
            ->when($v['closes_from'] ?? null, fn ($q, $d) => $q->where('closes_at', '>=', CarbonImmutable::parse($d, 'America/Santiago')->startOfDay()->utc()))
            ->when($v['closes_to'] ?? null, fn ($q, $d) => $q->where('closes_at', '<=', CarbonImmutable::parse($d, 'America/Santiago')->endOfDay()->utc()))
            ->when($request->boolean('open_only'), fn ($q) => $q->where('status_code', 'publicada')->where('closes_at', '>', now()))
            ->when(isset($v['min_amount']), fn ($q) => $q->where('available_amount_clp', '>=', $v['min_amount']))
            ->when(isset($v['max_amount']), fn ($q) => $q->where('available_amount_clp', '<=', $v['max_amount']))
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');

        $page = $query->paginate((int) ($v['per_page'] ?? 25))->withQueryString();

        return ApiResponse::paginated($page, CompraAgilResource::collection($page->items()), [
            'source' => $this->source(),
            'sync' => $this->syncSummary(),
        ]);
    }

    public function show(Request $request, string $code, CompraAgilDetailService $details): JsonResponse
    {
        if (! CompraAgilSearchQuery::isValidCode($code)) {
            return ApiResponse::error('VALIDATION_FAILED', 'Código de Compra Ágil con formato no válido.', 422);
        }

        $result = $details->get($code, $request->boolean('refresh'));

        return ApiResponse::ok(new CompraAgilResource($result['opportunity']), [
            'source' => $this->source(),
            'stale' => $result['stale'],
            'warning' => $result['warning'],
        ]);
    }

    /** Atribución obligatoria (condiciones de uso ChileCompra, punto 4). */
    private function source(): array
    {
        return [
            'name' => config('mercado_publico.portal.source_name'),
            'api' => 'API Compra Ágil V2',
            'portal_url' => config('mercado_publico.portal.home_url'),
            'data_origin' => 'official',
            'timezone' => 'America/Santiago',
        ];
    }

    private function syncSummary(): array
    {
        $state = MercadoPublicoSyncState::query()->where('key', MercadoPublicoSyncService::STATE_KEY_COMPRA_AGIL)->first();
        $lastFetched = PublicProcurementOpportunity::query()
            ->where('source_type', PublicProcurementOpportunity::TYPE_COMPRA_AGIL)
            ->max('last_fetched_at');

        return [
            'last_success_at' => $state?->last_success_finished_at?->toIso8601String(),
            'last_attempt_at' => $state?->last_attempt_at?->toIso8601String(),
            'last_status' => $state?->last_status,
            // Timestamps se guardan en UTC (APP_TIMEZONE=UTC).
            'last_item_fetched_at' => $lastFetched ? CarbonImmutable::parse($lastFetched, 'UTC')->toIso8601String() : null,
        ];
    }
}
