<?php

namespace Tests\Feature\Crm;

use App\Models\PortalAccess;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** TD-028: portal de clientes y proveedores. */
class PortalAccessTest extends CrmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function createSupplierWithPortal(string $email = 'ventas@proveedor.test'): array
    {
        $res = $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', [
            'organization' => ['legal_name' => 'Proveedor Ficticio SpA', 'rut' => '11.111.111-1', 'kind' => 'SME'],
            'portal_access' => ['email' => $email],
        ])->assertCreated()
            ->assertJsonPath('meta.portal_access.user.email', $email)
            ->assertJsonPath('meta.portal_access.must_change_password', true);

        return [$res->json('data.public_id'), $res->json('meta.portal_access.initial_password'), User::where('email', $email)->firstOrFail()];
    }

    public function test_creating_customer_creates_its_portal_user_with_initial_password(): void
    {
        $res = $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload(['portal_access' => ['email' => 'compras@cliente.test']]))
            ->assertCreated();
        $user = User::where('email', 'compras@cliente.test')->firstOrFail();

        $this->assertTrue(Hash::check($res->json('meta.portal_access.initial_password'), $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame(Role::where('key', 'customer_portal')->value('id'), $user->tenants()->first()->pivot->role_id);
        Notification::assertNothingSent();

        // No ocupa cupo del plan ni aparece como persona del equipo.
        $this->actingAs($this->adminA)->getJson('/api/v1/company')->assertJsonPath('data.seats.used', 1);
        $this->actingAs($this->adminA)->getJson('/api/v1/company/users')->assertJsonCount(1, 'data');
        $this->actingAs($this->adminA)->getJson('/api/v1/company/roles')->assertJsonMissing(['key' => 'customer_portal']);
    }

    public function test_customer_portal_sees_only_its_own_profile_and_requests(): void
    {
        $customer = $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload(['portal_access' => ['email' => 'compras@cliente.test']]))->json('data.public_id');
        $other = $this->actingAs($this->adminA)->postJson('/api/v1/customers', $this->customerPayload(['organization' => ['legal_name' => 'Otro Cliente SpA', 'rut' => '22.222.222-2']]))->json('data.public_id');
        $custId = DB::table('customers')->where('public_id', $customer)->value('id');
        $otherId = DB::table('customers')->where('public_id', $other)->value('id');
        foreach ([[$custId, 'SC-1', 'Resmas de papel'], [$otherId, 'SC-2', 'Compra ajena']] as [$cid, $n, $t]) {
            DB::table('purchase_requests')->insert(['public_id' => (string) Str::ulid(), 'tenant_id' => $this->tenantA->id, 'customer_id' => $cid, 'number' => $n, 'title' => $t, 'status' => 'SUBMITTED', 'created_at' => now(), 'updated_at' => now()]);
        }

        $portalUser = User::where('email', 'compras@cliente.test')->first()->forceFill(['must_change_password' => false]);
        $this->actingAs($portalUser)->getJson('/api/v1/auth/me')->assertJsonPath('data.portal.type', 'customer')->assertJsonPath('data.portal.name', 'Comercial Ficticia SpA');
        $this->actingAs($portalUser)->getJson('/api/v1/portal/profile')
            ->assertOk()
            ->assertJsonPath('data.organization.legal_name', 'Comercial Ficticia SpA')
            ->assertJsonCount(1, 'data.purchase_requests')
            ->assertJsonPath('data.purchase_requests.0.title', 'Resmas de papel')
            ->assertJsonMissing(['title' => 'Compra ajena']);

        // Sin acceso a los módulos de la empresa.
        $this->actingAs($portalUser)->getJson('/api/v1/customers')->assertStatus(403);
        $this->actingAs($portalUser)->getJson("/api/v1/customers/{$other}")->assertStatus(403);
        $this->actingAs($portalUser)->getJson('/api/v1/suppliers')->assertStatus(403);
        $this->actingAs($portalUser)->getJson('/api/v1/purchase-requests')->assertStatus(403);
        $this->actingAs($portalUser)->getJson('/api/v1/company/users')->assertStatus(403);
    }

    public function test_supplier_portal_sees_offerings_quotes_but_not_evaluations(): void
    {
        [$id, , $user] = $this->createSupplierWithPortal();
        $as = $this->actingAs($this->adminA);
        $as->postJson("/api/v1/suppliers/{$id}/offerings", ['kind' => 'PRODUCT', 'name' => 'Guantes de nitrilo', 'capacity_quantity' => 2000, 'capacity_period' => 'MONTH'])->assertCreated();
        $as->postJson("/api/v1/suppliers/{$id}/evaluations", ['price_score' => 2, 'quality_score' => 2, 'delivery_score' => 2, 'service_score' => 2, 'compliance_score' => 2, 'evaluated_on' => now('America/Santiago')->toDateString(), 'comment' => 'Comentario interno'])->assertSuccessful();

        $user->forceFill(['must_change_password' => false]);
        $res = $this->actingAs($user)->getJson('/api/v1/portal/profile')
            ->assertOk()
            ->assertJsonPath('data.type', 'supplier')
            ->assertJsonPath('data.offerings.0.name', 'Guantes de nitrilo')
            ->assertJsonPath('data.offerings.0.capacity_period', 'por mes');
        $this->assertStringNotContainsString('Comentario interno', $res->getContent());
        $this->assertArrayNotHasKey('evaluations', $res->json('data'));
        $this->actingAs($user)->getJson("/api/v1/suppliers/{$id}/evaluations")->assertStatus(403);
    }

    public function test_team_or_staff_email_is_rejected_without_creating_the_record(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', [
            'organization' => ['legal_name' => 'Proveedor Ficticio SpA', 'kind' => 'SME'],
            'portal_access' => ['email' => $this->adminA->email],
        ])->assertStatus(422)->assertJsonValidationErrors(['portal_access.email'], 'error.details');
        $this->assertDatabaseMissing('organizations', ['legal_name' => 'Proveedor Ficticio SpA']);

        $staff = $this->platformUser('tiare_admin');
        $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', [
            'organization' => ['legal_name' => 'Proveedor Ficticio SpA', 'kind' => 'SME'],
            'portal_access' => ['email' => $staff->email],
        ])->assertStatus(422);
    }

    public function test_access_management_from_the_record(): void
    {
        [$id, $first, $user] = $this->createSupplierWithPortal();
        $url = "/api/v1/suppliers/{$id}/portal-access";

        $this->actingAs($this->adminA)->getJson($url)->assertOk()->assertJsonPath('data.user.email', 'ventas@proveedor.test');
        $this->actingAs($this->adminA)->postJson($url, ['email' => 'otra@proveedor.test'])->assertStatus(422); // ya tiene acceso

        $new = $this->actingAs($this->adminA)->postJson("{$url}/password")->assertOk()->json('data.initial_password');
        $this->assertNotSame($first, $new);
        $this->assertTrue(Hash::check($new, $user->fresh()->password));

        $this->actingAs($this->adminA)->patchJson($url, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAs($user->fresh()->forceFill(['must_change_password' => false]))->getJson('/api/v1/portal/profile')->assertStatus(409); // sin acceso activo no tiene empresa
        $this->actingAs($this->adminA)->patchJson($url, ['is_active' => true])->assertOk();

        // Otra empresa no lo ve; un lector no lo gestiona.
        $this->actingAs($this->adminB)->getJson($url)->assertStatus(404);
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $this->actingAs($reader)->postJson("{$url}/password")->assertStatus(403);

        $this->actingAs($this->adminA)->deleteJson($url)->assertOk();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseMissing('portal_accesses', ['user_id' => $user->id]);
        $this->actingAs($this->adminA)->getJson($url)->assertOk()->assertJsonPath('data', null);
    }

    public function test_deleting_supplier_revokes_its_portal(): void
    {
        [$id, , $user] = $this->createSupplierWithPortal();
        $this->actingAs($this->adminA)->deleteJson("/api/v1/suppliers/{$id}")->assertOk();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertFalse($user->fresh()->tenants()->exists());
        $this->assertSame(0, PortalAccess::withoutTenantScope()->count());
    }

    public function test_portal_roles_cannot_be_assigned_or_edited_manually(): void
    {
        $admin = $this->platformUser('tiare_admin');
        $u = User::factory()->create();
        $this->actingAs($admin)->putJson("/api/v1/admin/users/{$u->public_id}/memberships/{$this->tenantA->public_id}", ['role_key' => 'supplier_portal'])->assertStatus(422);
        $this->actingAs($admin)->patchJson('/api/v1/admin/roles/supplier_portal', ['permissions' => ['suppliers.read']])->assertStatus(422);
        $this->actingAs($this->adminA)->postJson('/api/v1/company/users', ['name' => 'Alguien', 'email' => 'a@b.test', 'role_key' => 'customer_portal'])->assertStatus(422);
    }

    public function test_superadmin_console_command(): void
    {
        $this->artisan('tiare:superadmin', ['email' => 'Super@Ejemplo.test', '--name' => 'Persona Ficticia'])
            ->expectsOutputToContain('Superadministrador creado')
            ->expectsOutputToContain('se muestra solo esta vez')
            ->assertSuccessful();
        $user = User::where('email', 'super@ejemplo.test')->firstOrFail();
        $this->assertSame('platform_super_admin', $user->platformRole->key);
        $this->assertTrue($user->must_change_password);

        $this->artisan('tiare:superadmin', ['email' => 'nuevo@ejemplo.test'])->assertFailed(); // falta nombre
        $this->artisan('tiare:superadmin', ['email' => 'no-es-correo'])->assertFailed();
    }
}
