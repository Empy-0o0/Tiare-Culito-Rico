<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** TD-025: mantenedor de cuentas y roles del administrador TIARE. */
class AccountsAndRolesAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Notification::fake();
        $this->admin = $this->platformUser('tiare_admin');
        $this->tenant = Tenant::create(['name' => 'Empresa Ficticia', 'status' => 'ACTIVE']);
        $this->ensurePlan($this->tenant);
    }

    public function test_company_users_cannot_use_the_maintainer(): void
    {
        $companyAdmin = $this->userWithTenantRole('tenant_admin', $this->tenant);
        $this->actingAs($companyAdmin)->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->actingAs($companyAdmin)->getJson('/api/v1/admin/roles')->assertStatus(403);
    }

    public function test_admin_creates_account_with_company_role_and_password_link(): void
    {
        $res = $this->actingAs($this->admin)->postJson('/api/v1/admin/users', [
            'name' => 'Persona Ficticia',
            'email' => 'Persona@Ejemplo.test',
            'memberships' => [['tenant_public_id' => $this->tenant->public_id, 'role_key' => 'tenant_approver']],
        ])->assertCreated()
            ->assertJsonPath('data.email', 'persona@ejemplo.test')
            ->assertJsonPath('data.memberships.0.role.key', 'tenant_approver');

        $user = User::where('email', 'persona@ejemplo.test')->firstOrFail();
        $this->assertTrue($user->hasPermission('purchase_requests.approve', $this->tenant));
        $this->assertTrue($res->json('data.link_sent'));

        // Sin rol ni empresa no se crea.
        $this->actingAs($this->admin)->postJson('/api/v1/admin/users', ['name' => 'Nadie', 'email' => 'nadie@ejemplo.test'])->assertStatus(422);
        // Correo duplicado.
        $this->actingAs($this->admin)->postJson('/api/v1/admin/users', ['name' => 'Otra', 'email' => 'persona@ejemplo.test', 'platform_role_key' => 'supplier_manager'])->assertStatus(422);
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $this->actingAs($this->admin)->patchJson("/api/v1/admin/users/{$this->admin->public_id}", ['is_active' => false])->assertStatus(422);
        $this->actingAs($this->admin)->patchJson("/api/v1/admin/users/{$this->admin->public_id}", ['platform_role_key' => null])->assertStatus(422);
        // tiare_admin no puede crear superadministradores.
        $this->actingAs($this->admin)->postJson('/api/v1/admin/users', ['name' => 'Súper', 'email' => 'super@ejemplo.test', 'platform_role_key' => 'platform_super_admin'])->assertStatus(422);
        // Los roles administradores no se editan.
        $this->actingAs($this->admin)->patchJson('/api/v1/admin/roles/tiare_admin', ['permissions' => ['reports.read']])->assertStatus(422);
    }

    public function test_last_platform_admin_cannot_be_deactivated_but_others_can(): void
    {
        $other = $this->platformUser('tiare_admin');
        $other->createToken('x', ['reports.read']);

        $this->actingAs($this->admin)->patchJson("/api/v1/admin/users/{$other->public_id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(0, $other->tokens()->count(), 'Desactivar revoca sus tokens');

        // Ahora $this->admin es el último: otro admin (inactivo) no puede dejarlo sin rol; probamos vía servicio con un tercero.
        $third = $this->platformUser('platform_super_admin');
        $this->actingAs($third)->patchJson("/api/v1/admin/users/{$this->admin->public_id}", ['platform_role_key' => 'supplier_manager'])->assertOk();
        $this->actingAs($third)->patchJson("/api/v1/admin/users/{$third->public_id}", ['is_active' => false])->assertStatus(422);
    }

    public function test_memberships_respect_last_company_admin(): void
    {
        $companyAdmin = $this->userWithTenantRole('tenant_admin', $this->tenant);
        $url = "/api/v1/admin/users/{$companyAdmin->public_id}/memberships/{$this->tenant->public_id}";

        $this->actingAs($this->admin)->putJson($url, ['role_key' => 'tenant_read_only'])->assertStatus(422);
        $this->actingAs($this->admin)->deleteJson($url)->assertStatus(422);

        $second = $this->userWithTenantRole('tenant_admin', $this->tenant);
        $this->actingAs($this->admin)->putJson($url, ['role_key' => 'tenant_read_only'])->assertOk()->assertJsonPath('data.memberships.0.role.key', 'tenant_read_only');
        $this->actingAs($this->admin)->deleteJson($url)->assertOk()->assertJsonCount(0, 'data.memberships');
        $this->assertTrue($second->fresh()->belongsToTenant($this->tenant));
    }

    public function test_admin_lists_and_filters_accounts(): void
    {
        $this->userWithTenantRole('tenant_read_only', $this->tenant);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/users?role=tenant_read_only')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson("/api/v1/admin/users?tenant={$this->tenant->public_id}")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/admin/account-references')->assertOk()->assertJsonPath('data.tenants.0.name', 'Empresa Ficticia');
    }

    // ---------------- Roles ----------------

    public function test_custom_tenant_role_lifecycle(): void
    {
        $res = $this->actingAs($this->admin)->postJson('/api/v1/admin/roles', [
            'name' => 'Bodeguero', 'scope' => 'tenant', 'permissions' => ['inventory.read', 'inventory.manage'],
        ])->assertCreated()->assertJsonPath('data.is_system', false);
        $key = $res->json('data.key');
        $this->assertSame('custom_bodeguero', $key);

        // Un rol de empresa no recibe permisos de plataforma.
        $this->actingAs($this->admin)->patchJson("/api/v1/admin/roles/{$key}", ['permissions' => ['platform.users.manage']])->assertStatus(422);

        $user = User::factory()->create();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$user->public_id}/memberships/{$this->tenant->public_id}", ['role_key' => $key])->assertOk();
        $this->assertTrue($user->fresh()->hasPermission('inventory.manage', $this->tenant));

        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/roles/{$key}")->assertStatus(422); // en uso
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$user->public_id}/memberships/{$this->tenant->public_id}")->assertOk();
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/roles/{$key}")->assertOk();
    }

    public function test_editing_a_system_role_survives_reseed_and_can_be_reset(): void
    {
        $this->actingAs($this->admin)->patchJson('/api/v1/admin/roles/tenant_read_only', ['permissions' => ['reports.read']])
            ->assertOk()->assertJsonPath('data.customized', true)->assertJsonPath('data.permissions', ['reports.read']);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertSame(['reports.read'], Role::with('permissions')->where('key', 'tenant_read_only')->first()->permissionKeys());

        $this->actingAs($this->admin)->postJson('/api/v1/admin/roles/tenant_read_only/reset')
            ->assertOk()->assertJsonPath('data.customized', false);
        $this->assertContains('suppliers.read', Role::with('permissions')->where('key', 'tenant_read_only')->first()->permissionKeys());
        $this->actingAs($this->admin)->getJson('/api/v1/admin/roles')->assertOk()->assertJsonPath('meta.permissions.0.key', fn ($k) => is_string($k));
    }

    // ---------------- Eliminación (TD-031) ----------------

    public function test_protected_roles_cannot_be_deleted(): void
    {
        foreach (Role::UNDELETABLE_KEYS as $key) {
            $this->actingAs($this->admin)->deleteJson("/api/v1/admin/roles/{$key}")->assertStatus(422);
        }
        $this->actingAs($this->admin)->getJson('/api/v1/admin/roles')->assertOk()
            ->assertJsonPath('data', fn ($roles) => collect($roles)->firstWhere('key', 'tenant_admin')['deletable'] === false
                && collect($roles)->firstWhere('key', 'tenant_read_only')['deletable'] === true);
    }

    public function test_system_role_in_use_is_deleted_moving_accounts_and_is_not_recreated_by_seeder(): void
    {
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenant);

        // En uso: pide a qué rol mover las cuentas; no acepta otro alcance ni el mismo rol.
        $this->actingAs($this->admin)->deleteJson('/api/v1/admin/roles/tenant_read_only')->assertStatus(422)->assertJsonPath('error.details.replacement_key.0', fn ($m) => str_contains($m, '1 cuenta'));
        $this->actingAs($this->admin)->deleteJson('/api/v1/admin/roles/tenant_read_only', ['replacement_key' => 'supplier_manager'])->assertStatus(422);
        $this->actingAs($this->admin)->deleteJson('/api/v1/admin/roles/tenant_read_only', ['replacement_key' => 'tenant_read_only'])->assertStatus(422);

        $this->actingAs($this->admin)->deleteJson('/api/v1/admin/roles/tenant_read_only', ['replacement_key' => 'tenant_approver'])
            ->assertOk()->assertJsonPath('data.moved_accounts', 1);
        $this->assertTrue($reader->fresh()->hasPermission('purchase_requests.approve', $this->tenant));
        $this->assertNull(Role::where('key', 'tenant_read_only')->first());

        // El seeder respeta la eliminación y el rol ya no se ofrece.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertNull(Role::where('key', 'tenant_read_only')->first());
        $this->actingAs($this->admin)->getJson('/api/v1/admin/account-references')->assertOk()
            ->assertJsonPath('data.tenant_roles', fn ($r) => ! collect($r)->contains('key', 'tenant_read_only'));
        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$reader->public_id}/memberships/{$this->tenant->public_id}", ['role_key' => 'tenant_read_only'])->assertStatus(422);
    }

    public function test_admin_deletes_account_keeping_name_in_history(): void
    {
        $person = $this->userWithTenantRole('tenant_procurement_user', $this->tenant);
        $person->createToken('x', ['reports.read']);
        $email = $person->email;

        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$person->public_id}")->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertNull(User::find($person->id), 'No aparece para la aplicación');
        $trashed = User::withTrashed()->find($person->id);
        $this->assertSame($person->name, $trashed->name, 'El nombre se conserva para el historial');
        $this->assertNotSame($email, $trashed->email, 'El correo queda libre');
        $this->assertSame(0, $trashed->tokens()->count());
        $this->assertSame(0, $trashed->tenants()->count());
        $this->actingAs($this->admin)->getJson('/api/v1/admin/users')->assertOk()->assertJsonMissing(['public_id' => $person->public_id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.user_deleted', 'auditable_id' => $person->public_id]);

        // La sesión no puede recuperarla: ya no inicia sesión.
        $this->assertNull(auth()->guard('web')->getProvider()->retrieveById($person->id));
        // El correo se puede volver a usar.
        $this->actingAs($this->admin)->postJson('/api/v1/admin/users', ['name' => 'Otra persona', 'email' => $email, 'platform_role_key' => 'supplier_manager'])->assertCreated();
    }

    public function test_account_deletion_guards(): void
    {
        // Propia cuenta.
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$this->admin->public_id}")->assertStatus(422);
        // Último administrador de una empresa.
        $companyAdmin = $this->userWithTenantRole('tenant_admin', $this->tenant);
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$companyAdmin->public_id}")->assertStatus(422);
        // Un tiare_admin no elimina a un superadministrador.
        $super = $this->platformUser('platform_super_admin');
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$super->public_id}")->assertStatus(422);
        // Un superadministrador sí puede eliminar a otro administrador mientras quede alguno activo.
        $this->actingAs($super)->deleteJson("/api/v1/admin/users/{$this->admin->public_id}")->assertOk();
    }
}
