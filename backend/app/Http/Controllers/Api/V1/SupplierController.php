<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\SupplierStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\SupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Category;
use App\Models\Supplier;
use App\Services\Crm\PartyDeletionService;
use App\Services\Crm\PartyService;
use App\Services\Portal\PortalAccessService;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Supplier::class);
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(SupplierStatus::class)],
            'category_public_id' => ['nullable', 'string', 'size:26'],
            'region' => ['nullable', 'integer', 'between:1,16'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = Supplier::query()
            ->with(['organization', 'categories'])
            ->join('organizations', 'organizations.id', '=', 'suppliers.organization_id')
            ->select('suppliers.*')
            // Después del select: withAvg/withCount agregan columnas al select existente.
            ->withAvg('evaluations', 'overall_score')
            ->withCount(['evaluations', 'offerings as offerings_count' => fn ($o) => $o->where('is_active', true), 'documents as expired_documents_count' => fn ($d) => $d->whereNull('archived_at')->whereDate('expires_on', '<', now('America/Santiago')->toDateString())])
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('organizations.legal_name', 'like', $like)
                    ->orWhere('organizations.trade_name', 'like', $like)
                    ->orWhere('organizations.rut', 'like', $like)
                    // También por los insumos o servicios que entrega (TD-026).
                    ->orWhereHas('offerings', fn ($o) => $o->where('is_active', true)->where('name', 'like', $like)));
            })
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('suppliers.status', $s))
            ->when($v['category_public_id'] ?? null, function ($q, $publicId) {
                $categoryId = Category::query()->where('public_id', $publicId)->value('id') ?? 0;
                $q->whereHas('categories', fn ($c) => $c->where('categories.id', $categoryId));
            })
            // Cobertura: JSON con códigos de región; filtro en PHP-portable (LIKE sobre JSON acotado a enteros validados).
            ->when($v['region'] ?? null, fn ($q, $r) => $q->where(fn ($w) => $w
                ->where('suppliers.coverage_regions', 'like', '%['.$r.']%')
                ->orWhere('suppliers.coverage_regions', 'like', '%['.$r.',%')
                ->orWhere('suppliers.coverage_regions', 'like', '%,'.$r.',%')
                ->orWhere('suppliers.coverage_regions', 'like', '%,'.$r.']%')))
            ->orderBy('organizations.legal_name')
            ->paginate((int) ($v['per_page'] ?? 25));

        return ApiResponse::paginated($page, SupplierResource::collection($page->items()));
    }

    public function store(SupplierRequest $request, PartyService $service, PortalAccessService $portal): JsonResponse
    {
        Gate::authorize('create', Supplier::class);
        $data = $request->payload();
        $portalData = $data['portal_access'] ?? null;
        $tenant = app(CurrentTenant::class)->get();
        if ($portalData) {
            $portal->assertEmailAvailable($tenant, $portalData['email']);
        }

        // Proveedor y su acceso al portal en una sola transacción (TD-028).
        [$supplier, $grant] = DB::transaction(function () use ($service, $portal, $data, $portalData, $tenant, $request) {
            $supplier = $service->createSupplier($data, $request->user()->getKey());
            $grant = $portalData
                ? $portal->grant($tenant, $supplier, $portalData['email'], $portalData['name'] ?? $supplier->organization->legal_name, $request->user())
                : null;

            return [$supplier, $grant];
        });

        return ApiResponse::ok(new SupplierResource($supplier->load(['organization.contacts', 'categories'])), $grant ? [
            'portal_access' => $portal->present($tenant, $grant['access']) + ['initial_password' => $grant['initial_password']],
        ] : [], status: 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        Gate::authorize('view', $supplier);

        return ApiResponse::ok(new SupplierResource($supplier->load(['organization.contacts', 'categories'])));
    }

    public function update(SupplierRequest $request, Supplier $supplier, PartyService $service): JsonResponse
    {
        Gate::authorize('update', $supplier);
        $service->updateSupplier($supplier, $request->payload(), $request->user()->getKey());

        return ApiResponse::ok(new SupplierResource($supplier->refresh()->load(['organization.contacts', 'categories'])));
    }

    /** Elimina el proveedor (con sus evaluaciones y documentos) si no tiene historial de compras (TD-025). */
    public function destroy(Request $request, Supplier $supplier, PartyDeletionService $deletion): JsonResponse
    {
        Gate::authorize('delete', $supplier);
        $history = $deletion->supplierHistory($supplier);
        if ($history !== []) {
            $text = implode(', ', array_map(fn ($k, $n) => "{$n} {$k}", array_keys($history), $history));

            return ApiResponse::error('HAS_HISTORY', "No se puede eliminar: el proveedor tiene historial ({$text}). Cámbialo a estado Suspendido para que no reciba nuevas solicitudes.", 409, ['history' => [$text]]);
        }
        $deletion->deleteSupplier($supplier, $request->user()->getKey());

        return ApiResponse::ok(['deleted' => true]);
    }
}
