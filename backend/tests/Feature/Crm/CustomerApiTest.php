<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Organization;

class CustomerApiTest extends CrmTestCase
{
    public function test_admin_creates_customer_with_organization_and_primary_contact(): void
    {
        $response = $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload(['organization' => ['rut' => '123456785']]))
            ->assertCreated()
            ->assertJsonPath('data.status.code', 'PROSPECT')
            ->assertJsonPath('data.organization.rut', '12.345.678-5')
            ->assertJsonPath('data.organization.region.name', 'Metropolitana de Santiago')
            ->assertJsonPath('data.organization.contacts.0.is_primary', true)
            ->assertJsonPath('data.data_origin', 'client')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.organization.tenant_id');

        $this->assertDatabaseHas('customers', ['tenant_id' => $this->tenantA->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.created', 'tenant_id' => $this->tenantA->id, 'auditable_id' => $response->json('data.public_id')]);
    }

    public function test_invalid_rut_is_rejected(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload(['organization' => ['rut' => '12.345.678-9']]))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['organization.rut']]]);
    }

    public function test_duplicate_customer_in_same_tenant_is_rejected(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload())->assertCreated();
        $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload())
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['organization.rut']]]);
    }

    public function test_same_rut_can_exist_in_different_tenants(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload())->assertCreated();
        $this->actingAs($this->adminB)->postJson('/api/v1/customers', $this->customerPayload())->assertCreated();
        $this->assertSame(2, Organization::withoutTenantScope()->count());
    }

    public function test_tenant_id_in_payload_is_ignored(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload(['tenant_id' => $this->tenantB->id, 'organization' => ['tenant_id' => $this->tenantB->id]]))
            ->assertCreated();
        $this->assertSame($this->tenantA->id, Customer::withoutTenantScope()->first()->tenant_id);
        $this->assertSame($this->tenantA->id, Organization::withoutTenantScope()->first()->tenant_id);
    }

    public function test_list_search_and_status_filter(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload())->assertCreated();
        $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload(['organization' => ['legal_name' => 'Otra Empresa Ltda.', 'rut' => '11.111.111-1'], 'status' => 'ACTIVE']))->assertCreated();

        $this->actingAs($this->adminA)->getJson('/api/v1/customers')->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $this->actingAs($this->adminA)->getJson('/api/v1/customers?q=Otra')->assertJsonPath('meta.pagination.total', 1);
        $this->actingAs($this->adminA)->getJson('/api/v1/customers?q=12.345')->assertJsonPath('meta.pagination.total', 1);
        $this->actingAs($this->adminA)->getJson('/api/v1/customers?status=ACTIVE')->assertJsonPath('data.0.organization.legal_name', 'Otra Empresa Ltda.');
        $this->actingAs($this->adminA)->getJson('/api/v1/customers?status=VALIDADO')->assertStatus(422);
    }

    public function test_update_customer_and_organization(): void
    {
        $id = $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload())->json('data.public_id');

        $this->actingAs($this->adminA)->patchJson("/api/v1/customers/{$id}", ['status' => 'ACTIVE', 'organization' => ['trade_name' => 'Ficticia']])
            ->assertOk()->assertJsonPath('data.status.code', 'ACTIVE')->assertJsonPath('data.organization.trade_name', 'Ficticia');
    }

    public function test_read_only_user_can_list_but_not_create_or_update(): void
    {
        $id = $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload())->json('data.public_id');
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);

        $this->actingAs($reader)->getJson('/api/v1/customers')->assertOk();
        $this->actingAs($reader)->getJson("/api/v1/customers/{$id}")->assertOk();
        $this->actingAs($reader)->postJson('/api/v1/customers', $this->customerPayload(['organization' => ['rut' => '11.111.111-1']]))
            ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($reader)->patchJson("/api/v1/customers/{$id}", ['status' => 'ACTIVE'])->assertStatus(403);
    }

    public function test_approver_without_customers_permission_cannot_list(): void
    {
        $approver = $this->userWithTenantRole('tenant_approver', $this->tenantA);
        $this->actingAs($approver)->getJson('/api/v1/customers')->assertStatus(403);
    }

    public function test_add_contact_replaces_primary(): void
    {
        $orgId = $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload())->json('data.organization.public_id');

        $this->actingAs($this->adminA)->postJson("/api/v1/organizations/{$orgId}/contacts", ['contact' => ['full_name' => 'Segunda Persona', 'is_primary' => true]])
            ->assertCreated()->assertJsonPath('data.is_primary', true);

        $this->assertSame(1, Organization::withoutTenantScope()->where('public_id', $orgId)->first()->contacts()->where('is_primary', true)->count());
    }
}
