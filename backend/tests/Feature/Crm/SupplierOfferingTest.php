<?php

namespace Tests\Feature\Crm;

/** TD-026: insumos/servicios que entrega un proveedor y su capacidad. */
class SupplierOfferingTest extends CrmTestCase
{
    private function supplier(): string
    {
        return $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', [
            'organization' => ['legal_name' => 'Proveedor Ficticio SpA', 'kind' => 'SME'],
        ])->assertCreated()->json('data.public_id');
    }

    public function test_offering_crud_with_capacity(): void
    {
        $id = $this->supplier();
        $as = $this->actingAs($this->adminA);
        $category = $as->postJson('/api/v1/categories', ['name' => 'Aseo'])->assertCreated()->json('data.public_id');

        $o = $as->postJson("/api/v1/suppliers/{$id}/offerings", [
            'kind' => 'PRODUCT', 'name' => 'Papel higiénico doble hoja', 'category_public_id' => $category, 'unit_code' => 'EA',
            'capacity_quantity' => 5000, 'capacity_period' => 'MONTH', 'min_order_quantity' => 100, 'lead_time_days' => 3,
            'capacity_confirmed_on' => now('America/Santiago')->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.capacity.quantity', '5000.000')
            ->assertJsonPath('data.capacity.period.label', 'por mes')
            ->assertJsonPath('data.capacity.data_origin', 'supplier_declared')
            ->assertJsonPath('data.category.name', 'Aseo')
            ->json('data.public_id');

        $as->postJson("/api/v1/suppliers/{$id}/offerings", ['kind' => 'SERVICE', 'name' => 'Limpieza de oficinas'])->assertCreated();

        $as->getJson("/api/v1/suppliers/{$id}/offerings")->assertOk()->assertJsonCount(2, 'data');
        $as->getJson('/api/v1/suppliers?q=higiénico')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.offerings_count', 2);

        $as->patchJson("/api/v1/suppliers/{$id}/offerings/{$o}", ['capacity_quantity' => 8000])->assertOk()->assertJsonPath('data.capacity.quantity', '8000.000');
        $as->patchJson("/api/v1/suppliers/{$id}/offerings/{$o}", ['is_active' => false])->assertOk();
        $as->getJson("/api/v1/suppliers/{$id}/offerings")->assertOk()->assertJsonCount(1, 'data');
        $as->deleteJson("/api/v1/suppliers/{$id}/offerings/{$o}")->assertOk();
        $this->assertDatabaseMissing('supplier_offerings', ['public_id' => $o]);
    }

    public function test_offering_validation_permissions_and_isolation(): void
    {
        $id = $this->supplier();
        $as = $this->actingAs($this->adminA);

        $as->postJson("/api/v1/suppliers/{$id}/offerings", ['kind' => 'X', 'name' => 'a'])->assertStatus(422);
        $as->postJson("/api/v1/suppliers/{$id}/offerings", ['kind' => 'PRODUCT', 'name' => 'Guantes', 'capacity_quantity' => 10])->assertStatus(422); // falta período
        $as->postJson("/api/v1/suppliers/{$id}/offerings", ['kind' => 'PRODUCT', 'name' => 'Guantes', 'capacity_quantity' => -1, 'capacity_period' => 'DAY'])->assertStatus(422);

        // Categoría de otra empresa no se acepta.
        $foreignCategory = $this->actingAs($this->adminB)->postJson('/api/v1/categories', ['name' => 'Ajena'])->assertCreated()->json('data.public_id');
        $this->actingAs($this->adminA)->postJson("/api/v1/suppliers/{$id}/offerings", ['kind' => 'PRODUCT', 'name' => 'Guantes', 'category_public_id' => $foreignCategory])->assertStatus(422);

        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $this->actingAs($reader)->getJson("/api/v1/suppliers/{$id}/offerings")->assertOk();
        $this->actingAs($reader)->postJson("/api/v1/suppliers/{$id}/offerings", ['kind' => 'PRODUCT', 'name' => 'Guantes'])->assertStatus(403);
        $this->actingAs($this->adminB)->getJson("/api/v1/suppliers/{$id}/offerings")->assertStatus(404);
    }
}
