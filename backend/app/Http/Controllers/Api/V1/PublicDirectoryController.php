<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PublicBuyer;
use App\Services\MercadoPublico\V1\BuyerDirectoryService;
use App\Services\MercadoPublico\V1\SupplierLookupService;
use App\Support\ApiResponse;
use App\Support\ChileanRut;
use App\Support\MercadoPublicoAttribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Organismos compradores (BuscarComprador) y proveedores por RUT (BuscarProveedor). */
class PublicDirectoryController extends Controller
{
    public function buyers(Request $request, BuyerDirectoryService $directory): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'refresh' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        // Primera carga automática; luego solo a pedido y si el directorio está vencido.
        $refreshed = null;
        $empty = ! PublicBuyer::query()->exists();
        if ($empty || ($request->boolean('refresh') && $directory->isStale())) {
            $refreshed = $directory->refresh();
        }

        $page = PublicBuyer::query()
            ->where('source_system', 'mercado_publico')
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('external_code', $term));
            })
            ->orderBy('name')
            ->paginate((int) ($v['per_page'] ?? 30))
            ->withQueryString();

        return ApiResponse::paginated($page, collect($page->items())->map(fn (PublicBuyer $b) => [
            'code' => $b->external_code,
            'name' => $b->name,
        ])->values(), [
            'source' => MercadoPublicoAttribution::source('API V1 · BuscarComprador'),
            'last_fetched_at' => $directory->lastFetchedAt()?->toIso8601String(),
            'stale' => $directory->isStale(),
            'refreshed' => $refreshed !== null,
        ]);
    }

    public function supplierLookup(Request $request, SupplierLookupService $lookup): JsonResponse
    {
        $v = $request->validate(['rut' => ['required', 'string', 'max:16'], 'refresh' => ['nullable', 'boolean']]);
        if (! ChileanRut::isValid($v['rut'])) {
            return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['rut' => ['RUT no válido (revisa el dígito verificador).']]);
        }

        try {
            $result = $lookup->lookup($v['rut'], $request->boolean('refresh'));
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        return ApiResponse::ok([
            'code' => $result['supplier']->external_code,
            'rut' => $result['supplier']->rut,
            'name' => $result['supplier']->name,
            'last_fetched_at' => $result['supplier']->last_fetched_at?->toIso8601String(),
        ], [
            'source' => MercadoPublicoAttribution::source('API V1 · BuscarProveedor'),
            'cached' => $result['cached'],
        ]);
    }
}
