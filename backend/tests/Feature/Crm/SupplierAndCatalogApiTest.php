<?php

namespace Tests\Feature\Crm;

class SupplierAndCatalogApiTest extends CrmTestCase
{
    public function test_supplier_with_categories_and_coverage(): void
    {
        $as = $this->actingAs($this->adminA);
        $aseo = $as->postJson('/api/v1/categories', ['name' => 'Aseo e higiene'])->assertCreated()->json('data.public_id');
        $as->postJson('/api/v1/categories', ['name' => 'Aseo e higiene'])->assertStatus(422);

        $supplier = $as->postJson('/api/v1/suppliers', [
            'organization' => ['legal_name' => 'Proveedor Ficticio SpA', 'rut' => '11.111.111-1', 'kind' => 'SME'],
            'category_public_ids' => [$aseo],
            'coverage_regions' => [13, 5],
            'lead_time_days' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.status.code', 'REGISTERED')
            ->assertJsonPath('data.categories.0.name', 'Aseo e higiene')
            ->assertJsonPath('data.coverage_regions.1.name', 'Valparaíso')
            ->json('data');

        $as->getJson('/api/v1/suppliers?region=5')->assertJsonPath('meta.pagination.total', 1);
        $as->getJson('/api/v1/suppliers?region=1')->assertJsonPath('meta.pagination.total', 0);
        $as->getJson("/api/v1/suppliers?category_public_id={$aseo}")->assertJsonPath('meta.pagination.total', 1);

        $as->patchJson("/api/v1/suppliers/{$supplier['public_id']}", ['status' => 'APPROVED_FOR_QUOTES', 'category_public_ids' => []])
            ->assertOk()->assertJsonPath('data.status.label', 'Habilitado para cotizar')->assertJsonCount(0, 'data.categories');
        $this->assertDatabaseHas('audit_logs', ['action' => 'suppliers.updated', 'auditable_id' => $supplier['public_id']]);
    }

    public function test_supplier_status_validado_does_not_exist(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', [
            'organization' => ['legal_name' => 'X', 'kind' => 'SME'], 'status' => 'VALIDATED',
        ])->assertStatus(422);
    }

    public function test_organization_can_be_customer_and_supplier(): void
    {
        $as = $this->actingAs($this->adminA);
        $c = $as->postJson('/api/v1/customers', $this->customerPayload())->assertCreated()->json('data.organization.public_id');
        $s = $as->postJson('/api/v1/suppliers', $this->customerPayload())->assertCreated()->json('data.organization.public_id');
        $this->assertSame($c, $s);
    }

    public function test_product_crud_with_unit_category_and_unique_sku(): void
    {
        $as = $this->actingAs($this->adminA);
        $cat = $as->postJson('/api/v1/categories', ['name' => 'Oficina'])->json('data.public_id');

        $p = $as->postJson('/api/v1/products', [
            'name' => 'Resma carta', 'kind' => 'PRODUCT', 'unit_code' => 'BOX', 'sku' => 'OF-001',
            'category_public_id' => $cat, 'public_catalog_code' => '14111507',
        ])->assertCreated()
            ->assertJsonPath('data.unit.name', 'Caja')
            ->assertJsonPath('data.category.name', 'Oficina')
            ->json('data.public_id');

        $as->postJson('/api/v1/products', ['name' => 'Otro', 'kind' => 'PRODUCT', 'unit_code' => 'EA', 'sku' => 'OF-001'])
            ->assertStatus(422)->assertJsonPath('error.details.sku.0', 'Ya existe un producto con este SKU.');
        $as->postJson('/api/v1/products', ['name' => 'Malo', 'kind' => 'GADGET', 'unit_code' => 'XYZ'])->assertStatus(422);

        $as->patchJson("/api/v1/products/{$p}", ['is_active' => false, 'sku' => 'OF-001'])->assertOk()->assertJsonPath('data.is_active', false);
        $as->getJson('/api/v1/products?active=0')->assertJsonPath('meta.pagination.total', 1);
        $as->getJson('/api/v1/products?q=resma')->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_catalog_write_requires_manage_permission(): void
    {
        $user = $this->userWithTenantRole('tenant_procurement_user', $this->tenantA);
        $this->actingAs($user)->getJson('/api/v1/products')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/products', ['name' => 'X', 'kind' => 'PRODUCT', 'unit_code' => 'EA'])->assertStatus(403);
        $this->actingAs($user)->postJson('/api/v1/categories', ['name' => 'X'])->assertStatus(403);
        $this->actingAs($user)->postJson('/api/v1/suppliers', $this->customerPayload())->assertStatus(403);
    }

    public function test_references_endpoint(): void
    {
        $this->actingAs($this->adminA)->getJson('/api/v1/references')
            ->assertOk()
            ->assertJsonPath('data.supplier_statuses.0.code', 'REGISTERED')
            ->assertJsonFragment(['code' => 'EA', 'name' => 'Unidad']);
    }
}
