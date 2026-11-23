<?php

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Portal\PortalAccessService;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Eliminación de clientes y proveedores (TD-025).
 *
 * Solo se elimina un registro sin historial de compras: cotizaciones, órdenes, facturas o
 * solicitudes ligadas deben conservarse (trazabilidad, CLAUDE.md §7.5). Si hay historial,
 * se devuelve el detalle para que la persona lo deje inactivo/suspendido en vez de borrarlo.
 *
 * La organización (razón social, RUT, contactos) se elimina solo si no cumple otro rol:
 * una empresa puede ser cliente y proveedor a la vez.
 */
class PartyDeletionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PortalAccessService $portal,
    ) {}

    /** Antes de borrar el registro se revoca su acceso al portal (membresía, tokens). */
    private function revokePortal(Customer|Supplier $party, int $userId): void
    {
        $access = $this->portal->find($party);
        $tenant = app(CurrentTenant::class)->get();
        if ($access && $tenant) {
            $this->portal->revoke($tenant, $access, User::query()->findOrFail($userId));
        }
    }

    /** @return array<string, int> historial que impide eliminar (vacío = se puede) */
    public function supplierHistory(Supplier $supplier): array
    {
        $id = $supplier->getKey();

        return array_filter([
            'solicitudes de cotización' => DB::table('quotation_requests')->where('supplier_id', $id)->count(),
            'cotizaciones' => DB::table('supplier_quotations')->where('supplier_id', $id)->count(),
            'órdenes de compra' => DB::table('internal_purchase_orders')->where('supplier_id', $id)->count(),
            'facturas' => DB::table('invoices')->where('supplier_id', $id)->count(),
        ]);
    }

    /** @return array<string, int> */
    public function customerHistory(Customer $customer): array
    {
        return array_filter([
            'solicitudes de compra' => DB::table('purchase_requests')->where('customer_id', $customer->getKey())->count(),
        ]);
    }

    public function deleteSupplier(Supplier $supplier, int $userId): void
    {
        $this->revokePortal($supplier, $userId);
        $organization = $supplier->organization;
        $snapshot = ['name' => $organization?->legal_name, 'rut' => $organization?->rut];
        $paths = SupplierDocument::query()->where('supplier_id', $supplier->getKey())->pluck('storage_path')->all();

        DB::transaction(function () use ($supplier, $organization) {
            $supplier->categories()->detach();
            $supplier->delete(); // evaluaciones y documentos caen en cascada
            if ($organization && ! Customer::query()->where('organization_id', $organization->getKey())->exists()) {
                $organization->delete(); // contactos en cascada
            }
        });

        // Archivos fuera de la transacción: solo si los registros ya no existen.
        Storage::disk('local')->delete($paths);
        $this->audit->log('suppliers.deleted', $userId, 'supplier', $supplier->public_id, $snapshot + ['documents_deleted' => count($paths)]);
    }

    public function deleteCustomer(Customer $customer, int $userId): void
    {
        $this->revokePortal($customer, $userId);
        $organization = $customer->organization;
        $snapshot = ['name' => $organization?->legal_name, 'rut' => $organization?->rut];

        DB::transaction(function () use ($customer, $organization) {
            $customer->delete();
            if ($organization && ! Supplier::query()->where('organization_id', $organization->getKey())->exists()) {
                $organization->delete();
            }
        });

        $this->audit->log('customers.deleted', $userId, 'customer', $customer->public_id, $snapshot);
    }
}
