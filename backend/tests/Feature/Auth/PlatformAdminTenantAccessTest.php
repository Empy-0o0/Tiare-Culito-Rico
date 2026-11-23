<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** TD-032: el superadministrador y el administrador TIARE operan en cualquier empresa activa. */
class PlatformAdminTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->super = $this->platformUser('platform_super_admin');
    }

    public function test_without_any_company_the_reason_is_explained_instead_of_forbidden(): void
    {
        $this->actingAs($this->super)->getJson('/api/v1/suppliers')->assertStatus(409)->assertJsonPath('error.code', 'NO_ACTIVE_TENANT');
        $this->actingAs($this->super)->getJson('/api/v1/company')->assertStatus(409)->assertJsonPath('error.code', 'NO_ACTIVE_TENANT');
        $this->actingAs($this->super)->getJson('/api/v1/purchase-requests')->assertStatus(409);
        // La administración TIARE sigue disponible para crear la primera empresa.
        $this->actingAs($this->super)->getJson('/api/v1/admin/tenants')->assertOk();
    }

    public function test_super_admin_operates_in_any_active_company_without_membership(): void
    {
        $a = Tenant::create(['name' => 'Empresa A', 'status' => 'ACTIVE']);
        $b = Tenant::create(['name' => 'Empresa B', 'status' => 'ACTIVE']);
        Tenant::create(['name' => 'Empresa suspendida', 'status' => 'SUSPENDED']);
        $this->ensurePlan($a);
        $this->ensurePlan($b);

        $me = $this->actingAs($this->super)->getJson('/api/v1/auth/me')->assertOk()->json('data');
        $this->assertSame(['Empresa A', 'Empresa B'], array_column($me['tenants'], 'name'));
        $this->assertContains('suppliers.create', $me['permissions']);

        // Crea proveedores y clientes y entra a Configuración sin ser miembro.
        $this->actingAs($this->super)->postJson('/api/v1/suppliers', ['organization' => ['legal_name' => 'Proveedor Ficticio SpA', 'kind' => 'SME']])->assertCreated();
        $this->actingAs($this->super)->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.can_manage_users', true);

        // Cambia a otra empresa activa; no a una suspendida.
        $this->actingAs($this->super)->postJson('/api/v1/auth/tenant', ['tenant_public_id' => $b->public_id], ['Referer' => 'http://localhost:5173/'])->assertOk()->assertJsonPath('data.current_tenant.name', 'Empresa B');
    }

    public function test_company_users_still_only_see_their_own_companies(): void
    {
        $a = Tenant::create(['name' => 'Empresa A', 'status' => 'ACTIVE']);
        $b = Tenant::create(['name' => 'Empresa B', 'status' => 'ACTIVE']);
        $this->ensurePlan($b);
        $member = $this->userWithTenantRole('tenant_admin', $a);

        $me = $this->actingAs($member)->getJson('/api/v1/auth/me')->assertOk()->json('data');
        $this->assertSame(['Empresa A'], array_column($me['tenants'], 'name'));
        $this->actingAs($member)->postJson('/api/v1/auth/tenant', ['tenant_public_id' => $b->public_id], ['Referer' => 'http://localhost:5173/'])->assertNotFound();
    }
}
