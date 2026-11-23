<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Support\CurrentTenant;
use LogicException;

/** Usuario de tenant A no puede consultar ni editar información de tenant B (CLAUDE.md §14.2, §14.3 IDOR). */
class TenantIsolationTest extends CrmTestCase
{
    public function test_records_of_another_tenant_are_invisible_and_not_editable(): void
    {
        $customerB = $this->actingAs($this->adminB)->postJson('/api/v1/customers', $this->customerPayload())->json('data');
        $supplierB = $this->actingAs($this->adminB)->postJson('/api/v1/suppliers', $this->customerPayload())->json('data');
        $productB = $this->actingAs($this->adminB)->postJson('/api/v1/products', ['name' => 'Producto B', 'kind' => 'PRODUCT', 'unit_code' => 'EA', 'sku' => 'B-1'])->json('data');
        $categoryB = $this->actingAs($this->adminB)->postJson('/api/v1/categories', ['name' => 'Categoría B'])->json('data');

        $as = $this->actingAs($this->adminA);
        $as->getJson('/api/v1/customers')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $as->getJson('/api/v1/suppliers')->assertJsonPath('meta.pagination.total', 0);
        $as->getJson('/api/v1/products')->assertJsonPath('meta.pagination.total', 0);
        $as->getJson('/api/v1/categories')->assertJsonCount(0, 'data');

        $as->getJson("/api/v1/customers/{$customerB['public_id']}")->assertNotFound();
        $as->patchJson("/api/v1/customers/{$customerB['public_id']}", ['status' => 'INACTIVE'])->assertNotFound();
        $as->getJson("/api/v1/suppliers/{$supplierB['public_id']}")->assertNotFound();
        $as->patchJson("/api/v1/products/{$productB['public_id']}", ['name' => 'Hackeado'])->assertNotFound();
        $as->postJson("/api/v1/organizations/{$customerB['organization']['public_id']}/contacts", ['contact' => ['full_name' => 'Intruso']])->assertNotFound();

        // Referencias cruzadas en el payload también se rechazan.
        $as->postJson('/api/v1/products', ['name' => 'P', 'kind' => 'PRODUCT', 'unit_code' => 'EA', 'category_public_id' => $categoryB['public_id']])->assertStatus(422);
        $as->postJson('/api/v1/suppliers', $this->customerPayload(['category_public_ids' => [$categoryB['public_id']]]))->assertStatus(422);

        $this->assertSame('Producto B', $this->actingAs($this->adminB)->getJson("/api/v1/products/{$productB['public_id']}")->json('data.name'));
        $this->assertSame('PROSPECT', $this->actingAs($this->adminB)->getJson("/api/v1/customers/{$customerB['public_id']}")->json('data.status.code'));
    }

    public function test_queries_without_active_tenant_return_nothing(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload())->assertCreated();
        app(CurrentTenant::class)->set(null);

        $this->assertSame(0, Customer::count());
        $this->assertSame(1, Customer::withoutTenantScope()->count());
    }

    public function test_creating_without_active_tenant_fails_closed(): void
    {
        app(CurrentTenant::class)->set(null);
        $this->expectException(LogicException::class);
        Customer::create(['organization_id' => 1, 'status' => 'PROSPECT']);
    }

    public function test_tenant_cannot_be_changed_on_existing_record(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload())->assertCreated();
        $customer = Customer::withoutTenantScope()->first();

        $this->expectException(LogicException::class);
        $customer->tenant_id = $this->tenantB->id;
        $customer->save();
    }
}
