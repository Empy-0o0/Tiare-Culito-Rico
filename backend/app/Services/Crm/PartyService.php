<?php

namespace App\Services\Crm;

use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\SupplierStatus;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Casos de uso de clientes y proveedores (transaccionales y auditados). */
class PartyService
{
    private const CUSTOMER_FIELDS = ['status', 'plan', 'source', 'next_action_at', 'next_action_summary'];

    private const SUPPLIER_FIELDS = ['status', 'coverage_regions', 'payment_terms', 'lead_time_days', 'notes'];

    public function __construct(
        private readonly OrganizationService $organizations,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function createCustomer(array $data, int $userId): Customer
    {
        return DB::transaction(function () use ($data, $userId) {
            $org = $this->organizations->findOrCreate($data['organization'], $userId);
            if (Customer::query()->where('organization_id', $org->id)->exists()) {
                throw ValidationException::withMessages(['organization.rut' => 'Esta organización ya está registrada como cliente.']);
            }

            $customer = Customer::create(array_merge(
                ['status' => CustomerStatus::Prospect->value],
                array_intersect_key($data, array_flip(self::CUSTOMER_FIELDS)),
                ['organization_id' => $org->id, 'owner_user_id' => $userId],
            ));

            if (! empty($data['contact']['full_name'])) {
                $this->organizations->addContact($org, $data['contact']);
            }

            $this->audit->log('customers.created', $userId, 'customer', $customer->public_id);

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateCustomer(Customer $customer, array $data, int $userId): Customer
    {
        return DB::transaction(function () use ($customer, $data, $userId) {
            if (isset($data['organization'])) {
                $this->organizations->update($customer->organization, $data['organization'], $userId);
            }
            $customer->fill(array_intersect_key($data, array_flip(self::CUSTOMER_FIELDS)))->save();
            $this->audit->log('customers.updated', $userId, 'customer', $customer->public_id, ['fields' => array_keys($data)]);

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    public function createSupplier(array $data, int $userId): Supplier
    {
        return DB::transaction(function () use ($data, $userId) {
            $org = $this->organizations->findOrCreate($data['organization'], $userId);
            if (Supplier::query()->where('organization_id', $org->id)->exists()) {
                throw ValidationException::withMessages(['organization.rut' => 'Esta organización ya está registrada como proveedor.']);
            }

            $supplier = Supplier::create(array_merge(
                ['status' => SupplierStatus::Registered->value],
                array_intersect_key($data, array_flip(self::SUPPLIER_FIELDS)),
                ['organization_id' => $org->id],
            ));
            $supplier->categories()->sync($data['category_ids'] ?? []);

            if (! empty($data['contact']['full_name'])) {
                $this->organizations->addContact($org, $data['contact']);
            }

            $this->audit->log('suppliers.created', $userId, 'supplier', $supplier->public_id);

            return $supplier;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateSupplier(Supplier $supplier, array $data, int $userId): Supplier
    {
        return DB::transaction(function () use ($supplier, $data, $userId) {
            if (isset($data['organization'])) {
                $this->organizations->update($supplier->organization, $data['organization'], $userId);
            }
            $before = $supplier->status;
            $supplier->fill(array_intersect_key($data, array_flip(self::SUPPLIER_FIELDS)))->save();
            if (array_key_exists('category_ids', $data)) {
                $supplier->categories()->sync($data['category_ids'] ?? []);
            }
            $this->audit->log('suppliers.updated', $userId, 'supplier', $supplier->public_id, [
                'fields' => array_keys($data),
                'status_from' => $before?->value,
                'status_to' => $supplier->status?->value,
            ]);

            return $supplier;
        });
    }
}
