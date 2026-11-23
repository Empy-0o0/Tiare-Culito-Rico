<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicProcurement\TenderIndexRequest;
use App\Http\Resources\TenderResource;
use App\Models\PublicProcurementOpportunity;
use App\Services\MercadoPublico\Sync\MercadoPublicoQuotaService;
use App\Services\MercadoPublico\V1\TenderService;
use App\Support\ApiResponse;
use App\Support\MercadoPublicoAttribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Licitaciones públicas y privadas (API V1 licitaciones.json).
 * Sin parámetros de consulta: muestra lo guardado. Con date/source_status/buyer_code/supplier_code:
 * consulta Mercado Público (una solicitud, cacheada) y restringe el listado a ese resultado.
 */
class TenderController extends Controller
{
    public function index(TenderIndexRequest $request, TenderService $service, MercadoPublicoQuotaService $quota): JsonResponse
    {
        $v = $request->validated();
        $sourceQuery = $request->sourceQuery();
        $search = $sourceQuery ? $service->search($sourceQuery, $request->boolean('refresh')) : null;

        $sort = $v['sort'] ?? 'closes_at';
        $direction = $v['direction'] ?? ($sort === 'closes_at' ? 'asc' : 'desc');

        $query = PublicProcurementOpportunity::query()
            ->where('source_system', PublicProcurementOpportunity::SOURCE_SYSTEM)
            ->where('source_type', PublicProcurementOpportunity::TYPE_LICITACION)
            ->when($search !== null, fn ($q) => $q->whereIn('external_code', $search['codes'] ?: ['__none__']))
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)
                    ->orWhere('external_code', 'like', $like)
                    ->orWhere('buyer_name', 'like', $like));
            })
            ->when(isset($v['status']), fn ($q) => $q->where('status_code', (string) $v['status']))
            ->when($v['type'] ?? null, fn ($q, string $t) => $q->where('procedure_type', $t))
            ->when($v['region'] ?? null, fn ($q, int $r) => $q->where('region_code', $r))
            ->orderByRaw("{$sort} IS NULL")   // nulos al final en MariaDB y SQLite
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');

        $page = $query->paginate((int) ($v['per_page'] ?? 25))->withQueryString();

        return ApiResponse::paginated($page, TenderResource::collection($page->items()), [
            'source' => MercadoPublicoAttribution::source('API Licitaciones V1'),
            'search' => $search ? [
                'query' => $search['query'],
                'fetched_at' => $search['fetched_at'],
                'from_cache' => $search['from_cache'],
                'received' => count($search['codes']),
            ] : null,
            'quota' => $quota->usageToday('v1'),
        ]);
    }

    public function show(Request $request, string $code, TenderService $service): JsonResponse
    {
        $result = $service->detail($code, $request->boolean('refresh'));

        return ApiResponse::ok(new TenderResource($result['record']), [
            'source' => MercadoPublicoAttribution::source('API Licitaciones V1'),
            'stale' => $result['stale'],
            'warning' => $result['warning'],
            'portal_hint' => 'Busca el código en www.mercadopublico.cl para ver bases, anexos y foro.',
        ]);
    }
}
