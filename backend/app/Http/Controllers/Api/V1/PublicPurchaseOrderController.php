<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicProcurement\PurchaseOrderIndexRequest;
use App\Http\Resources\PublicPurchaseOrderResource;
use App\Models\PublicPurchaseOrder;
use App\Services\MercadoPublico\Sync\MercadoPublicoQuotaService;
use App\Services\MercadoPublico\V1\PurchaseOrderService;
use App\Support\ApiResponse;
use App\Support\MercadoPublicoAttribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Órdenes de compra públicas (API V1 ordenesdecompra.json). Mismo esquema que licitaciones. */
class PublicPurchaseOrderController extends Controller
{
    public function index(PurchaseOrderIndexRequest $request, PurchaseOrderService $service, MercadoPublicoQuotaService $quota): JsonResponse
    {
        $v = $request->validated();
        $sourceQuery = $request->sourceQuery();
        $search = $sourceQuery ? $service->search($sourceQuery, $request->boolean('refresh')) : null;

        $sort = $v['sort'] ?? 'issued_at';
        $direction = $v['direction'] ?? 'desc';

        $query = PublicPurchaseOrder::query()
            ->where('source_system', PublicPurchaseOrder::SOURCE_SYSTEM)
            ->when($search !== null, fn ($q) => $q->whereIn('external_code', $search['codes'] ?: ['__none__']))
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)
                    ->orWhere('external_code', 'like', $like)
                    ->orWhere('buyer_name', 'like', $like)
                    ->orWhere('supplier_name', 'like', $like));
            })
            ->when(isset($v['status']), fn ($q) => $q->where('status_code', (int) $v['status']))
            ->orderByRaw("{$sort} IS NULL")
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');

        $page = $query->paginate((int) ($v['per_page'] ?? 25))->withQueryString();

        return ApiResponse::paginated($page, PublicPurchaseOrderResource::collection($page->items()), [
            'source' => MercadoPublicoAttribution::source('API Órdenes de Compra V1'),
            'search' => $search ? [
                'query' => $search['query'],
                'fetched_at' => $search['fetched_at'],
                'from_cache' => $search['from_cache'],
                'received' => count($search['codes']),
            ] : null,
            'quota' => $quota->usageToday('v1'),
        ]);
    }

    public function show(Request $request, string $code, PurchaseOrderService $service): JsonResponse
    {
        $result = $service->detail($code, $request->boolean('refresh'));

        return ApiResponse::ok(new PublicPurchaseOrderResource($result['record']), [
            'source' => MercadoPublicoAttribution::source('API Órdenes de Compra V1'),
            'stale' => $result['stale'],
            'warning' => $result['warning'],
        ]);
    }
}
