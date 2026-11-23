<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Domain\Crm\CustomerStatus;
use App\Models\Customer;
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

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(CustomerStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = Customer::query()
            ->with(['organization', 'owner'])
            ->join('organizations', 'organizations.id', '=', 'customers.organization_id')
            ->select('customers.*')
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('organizations.legal_name', 'like', $like)
                    ->orWhere('organizations.trade_name', 'like', $like)
                    ->orWhere('organizations.rut', 'like', $like)
                    ->orWhere('organizations.email', 'like', $like));
            })
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('customers.status', $s))
            ->orderBy('organizations.legal_name')
            ->paginate((int) ($v['per_page'] ?? 25));

        return ApiResponse::paginated($page, CustomerResource::collection($page->items()));
    }

    public function store(CustomerRequest $request, PartyService $service, PortalAccessService $portal): JsonResponse
    {
        Gate::authorize('create', Customer::class);
        $data = $request->validated();
        $portalData = $data['portal_access'] ?? null;
        $tenant = app(CurrentTenant::class)->get();
        if ($portalData) {
            $portal->assertEmailAvailable($tenant, $portalData['email']);
        }

        // Cliente y su acceso al portal en una sola transacción: nunca queda uno sin el otro (TD-028).
        [$customer, $grant] = DB::transaction(function () use ($service, $portal, $data, $portalData, $tenant, $request) {
            $customer = $service->createCustomer($data, $request->user()->getKey());
            $grant = $portalData
                ? $portal->grant($tenant, $customer, $portalData['email'], $portalData['name'] ?? $customer->organization->legal_name, $request->user())
                : null;

            return [$customer, $grant];
        });

        return ApiResponse::ok(new CustomerResource($customer->load(['organization.contacts', 'owner'])), $grant ? [
            'portal_access' => $portal->present($tenant, $grant['access']) + ['initial_password' => $grant['initial_password']],
        ] : [], status: 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        Gate::authorize('view', $customer);

        return ApiResponse::ok(new CustomerResource($customer->load(['organization.contacts', 'owner'])));
    }

    public function update(CustomerRequest $request, Customer $customer, PartyService $service): JsonResponse
    {
        Gate::authorize('update', $customer);
        $service->updateCustomer($customer, $request->validated(), $request->user()->getKey());

        return ApiResponse::ok(new CustomerResource($customer->refresh()->load(['organization.contacts', 'owner'])));
    }

    /** Elimina el cliente si no tiene historial de compras (TD-025). */
    public function destroy(Request $request, Customer $customer, PartyDeletionService $deletion): JsonResponse
    {
        Gate::authorize('delete', $customer);
        $history = $deletion->customerHistory($customer);
        if ($history !== []) {
            return ApiResponse::error('HAS_HISTORY', 'No se puede eliminar: el cliente tiene historial ('.self::describe($history).'). Cámbialo a estado Inactivo para conservar la trazabilidad.', 409, ['history' => array_map(fn ($k, $n) => "{$n} {$k}", array_keys($history), $history)]);
        }
        $deletion->deleteCustomer($customer, $request->user()->getKey());

        return ApiResponse::ok(['deleted' => true]);
    }

    /** @param array<string, int> $history */
    private static function describe(array $history): string
    {
        return implode(', ', array_map(fn ($k, $n) => "{$n} {$k}", array_keys($history), $history));
    }
}
