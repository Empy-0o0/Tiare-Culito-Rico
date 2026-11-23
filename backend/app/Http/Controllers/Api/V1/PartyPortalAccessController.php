<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PortalAccess;
use App\Models\Supplier;
use App\Services\Portal\PortalAccessService;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Gestión del acceso al portal desde la ficha del cliente o proveedor (TD-028).
 * Ver: permiso de lectura del registro. Crear, regenerar contraseña, activar/desactivar y revocar:
 * permiso de edición del registro (customers.update / suppliers.update).
 */
class PartyPortalAccessController extends Controller
{
    public function __construct(
        private readonly PortalAccessService $portal,
        private readonly CurrentTenant $current,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $party = $this->party($request, 'view');
        $access = $this->portal->find($party);

        return ApiResponse::ok($access ? $this->portal->present($this->current->get(), $access) : null);
    }

    public function store(Request $request): JsonResponse
    {
        $party = $this->party($request, 'update');
        $v = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'name' => ['nullable', 'string', 'min:2', 'max:120'],
        ]);
        $result = $this->portal->grant($this->current->get(), $party, $v['email'], $v['name'] ?? $party->organization->legal_name, $request->user());

        return ApiResponse::ok($this->portal->present($this->current->get(), $result['access']) + [
            'initial_password' => $result['initial_password'],
            'message' => $result['created']
                ? 'Acceso creado con contraseña de inicio. Entrégala por un canal seguro: deberá cambiarla al ingresar.'
                : 'El correo ya tenía cuenta en TIARE: ahora también accede a este portal con su contraseña actual.',
        ], status: 201);
    }

    public function password(Request $request): JsonResponse
    {
        $access = $this->access($request);

        return ApiResponse::ok([
            'initial_password' => $this->portal->regeneratePassword($this->current->get(), $access, $request->user()),
            'message' => 'Contraseña de inicio generada. Se cerraron sus sesiones y deberá cambiarla al ingresar.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $access = $this->access($request);
        $v = $request->validate(['is_active' => ['required', 'boolean']]);
        $this->portal->setActive($this->current->get(), $access, (bool) $v['is_active'], $request->user());

        return ApiResponse::ok($this->portal->present($this->current->get(), $access->fresh('user')));
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->portal->revoke($this->current->get(), $this->access($request), $request->user());

        return ApiResponse::ok(['revoked' => true]);
    }

    private function access(Request $request): PortalAccess
    {
        $access = $this->portal->find($this->party($request, 'update'));
        abort_if($access === null, 404);

        return $access;
    }

    /** El registro se busca con el global scope del tenant: uno ajeno responde 404. */
    private function party(Request $request, string $ability): Customer|Supplier
    {
        // La misma acción sirve a clientes y proveedores: el registro se resuelve por public_id
        // con el global scope del tenant activo (uno de otra empresa no existe → 404).
        $party = match (true) {
            $request->route('customer') !== null => Customer::query()->where('public_id', (string) $request->route('customer'))->first(),
            $request->route('supplier') !== null => Supplier::query()->where('public_id', (string) $request->route('supplier'))->first(),
            default => null,
        };
        abort_unless($party instanceof Customer || $party instanceof Supplier, 404);
        Gate::authorize($ability, $party);

        return $party;
    }
}
