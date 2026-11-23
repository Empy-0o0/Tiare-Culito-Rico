<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** TD-024: planes por empresa, administración TIARE y tokens API. */
class PlansAdminAndTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Notification::fake();
    }

    private function tenantWithPlan(string $planKey, string $name = 'Empresa'): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'status' => 'ACTIVE']);
        $this->ensurePlan($tenant, $planKey);

        return $tenant;
    }

    /** Token emitido directamente (sin pasar por HTTP) para probar su uso. */
    private function tokenFor(User $user, Tenant $tenant, array $abilities, ?\DateTimeInterface $expires = null): string
    {
        $new = $user->createToken('integración', $abilities, $expires ?? now()->addDays(30));
        $new->accessToken->forceFill(['tenant_id' => $tenant->id])->save();

        return $new->plainTextToken;
    }

    private function bearer(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    // ---------------- Planes ----------------

    public function test_plan_limits_role_permissions_to_its_modules(): void
    {
        $tenant = $this->tenantWithPlan('public');
        $admin = $this->userWithTenantRole('tenant_admin', $tenant);

        $this->assertTrue($admin->hasPermission('public_procurement.read', $tenant));
        $this->assertFalse($admin->hasPermission('purchase_requests.read', $tenant));
        $this->assertTrue($admin->hasPermission('users.manage', $tenant), 'Los permisos CORE no dependen del plan');

        $this->actingAs($admin)->getJson('/api/v1/purchase-requests')->assertStatus(403);
        $this->actingAs($admin)->getJson('/api/v1/public-procurement/compra-agil')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.current_tenant.subscription.plan.key', 'public')
            ->assertJsonMissing(['purchase_requests.read']);
    }

    public function test_tenant_without_current_subscription_gets_no_module_permissions(): void
    {
        $tenant = Tenant::create(['name' => 'Sin plan', 'status' => 'ACTIVE']);
        $admin = $this->userWithTenantRole('tenant_admin', $tenant);
        $tenant->subscriptions()->update(['status' => 'active', 'starts_on' => '2020-01-01', 'ends_on' => '2020-12-31']);
        $admin->refresh();
        $fresh = Tenant::find($tenant->id);

        $this->assertFalse($admin->hasPermission('suppliers.read', $fresh));
        $this->actingAs($admin)->getJson('/api/v1/company')
            ->assertOk()
            ->assertJsonPath('data.subscription', null);
    }

    public function test_company_sees_only_its_own_plan_and_cannot_use_admin_api(): void
    {
        $mine = $this->tenantWithPlan('start', 'Mía');
        $this->tenantWithPlan('saas', 'Otra');
        $admin = $this->userWithTenantRole('tenant_admin', $mine);

        $this->actingAs($admin)->getJson('/api/v1/company')
            ->assertOk()
            ->assertJsonPath('data.name', 'Mía')
            ->assertJsonPath('data.subscription.plan.key', 'start')
            ->assertJsonPath('data.seats.max', 5);

        $this->actingAs($admin)->getJson('/api/v1/admin/tenants')->assertStatus(403);
        $this->actingAs($admin)->getJson('/api/v1/admin/plans')->assertStatus(403);
    }

    public function test_plan_user_limit_blocks_new_invitations(): void
    {
        $tenant = $this->tenantWithPlan('start');
        $admin = $this->userWithTenantRole('tenant_admin', $tenant);
        foreach (range(1, 4) as $_) {
            $this->userWithTenantRole('tenant_read_only', $tenant);
        }

        $this->actingAs($admin)->postJson('/api/v1/company/users', ['name' => 'Sexta Persona', 'email' => 'sexta@example.test', 'role_key' => 'tenant_read_only'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.email.0', fn ($m) => str_contains($m, 'hasta 5 personas'));
    }

    // ---------------- Administración TIARE ----------------

    public function test_platform_admin_creates_company_with_plan_and_company_admin(): void
    {
        $tiare = $this->platformUser('tiare_admin');

        $res = $this->actingAs($tiare)->postJson('/api/v1/admin/tenants', [
            'name' => 'Constructora Andes',
            'rut' => '76.086.428-5',
            'plan_key' => 'growth',
            'admin_name' => 'Ana Pérez',
            'admin_email' => 'ana@andes.test',
        ])->assertCreated()
            ->assertJsonPath('data.subscription.plan.key', 'growth')
            ->assertJsonPath('data.seats.used', 1);

        $tenant = Tenant::where('public_id', $res->json('data.public_id'))->firstOrFail();
        $ana = User::where('email', 'ana@andes.test')->firstOrFail();
        $this->assertTrue($ana->hasPermission('inventory.read', $tenant));
        $this->assertFalse($ana->hasPermission('public_procurement.read', $tenant), 'Growth no incluye Mercado Público');
        $this->assertFalse($tiare->belongsToTenant($tenant), 'El admin TIARE no queda como miembro');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.tenant_created']);
    }

    public function test_platform_admin_changes_plan_and_suspends_company(): void
    {
        $tiare = $this->platformUser('tiare_admin');
        $tenant = $this->tenantWithPlan('start');
        $member = $this->userWithTenantRole('tenant_admin', $tenant);

        $this->actingAs($tiare)->postJson("/api/v1/admin/tenants/{$tenant->public_id}/subscription", ['plan_key' => 'public'])
            ->assertOk()->assertJsonPath('data.subscription.plan.key', 'public');
        $this->assertSame(1, $tenant->subscriptions()->where('status', 'active')->count());
        $this->assertTrue($member->fresh()->hasPermission('public_procurement.read', Tenant::find($tenant->id)));

        $this->actingAs($tiare)->getJson("/api/v1/admin/tenants/{$tenant->public_id}")
            ->assertOk()->assertJsonCount(2, 'data.subscriptions')->assertJsonCount(1, 'data.users');

        $this->actingAs($tiare)->patchJson("/api/v1/admin/tenants/{$tenant->public_id}", ['status' => 'SUSPENDED'])->assertOk();
        $this->actingAs($member->fresh())->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.current_tenant', null);
    }

    public function test_admin_validation_and_unknown_tenant(): void
    {
        $tiare = $this->platformUser('tiare_admin');
        $this->actingAs($tiare)->postJson('/api/v1/admin/tenants', ['name' => 'X', 'plan_key' => 'inexistente'])
            ->assertStatus(422);
        $this->actingAs($tiare)->getJson('/api/v1/admin/tenants/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertStatus(404);
        $this->actingAs($tiare)->getJson('/api/v1/admin/plans')->assertOk()->assertJsonCount(5, 'data');
    }

    // ---------------- Tokens API ----------------

    public function test_token_is_issued_once_with_prefix_and_limited_abilities(): void
    {
        $tenant = $this->tenantWithPlan('saas');
        $admin = $this->userWithTenantRole('tenant_admin', $tenant);

        $res = $this->actingAs($admin)->postJson('/api/v1/auth/tokens', [
            'name' => 'ERP interno',
            'abilities' => ['suppliers.read'],
            'expires_in_days' => 30,
        ])->assertCreated();

        $plain = $res->json('data.plain_text_token');
        $this->assertMatchesRegularExpression('/^\d+\|tiare_[A-Za-z0-9]{40}[0-9a-f]{8}$/', $plain);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plain]);
        $this->assertDatabaseHas('personal_access_tokens', ['tenant_id' => $tenant->id, 'name' => 'ERP interno']);

        $this->actingAs($admin)->getJson('/api/v1/auth/tokens')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['plain_text_token'])
            ->assertJsonPath('meta.available_abilities', fn ($a) => in_array('suppliers.read', $a) && ! in_array('users.manage', $a) && ! in_array('api_tokens.manage', $a));
    }

    public function test_token_cannot_be_granted_permissions_the_user_lacks(): void
    {
        $tenant = $this->tenantWithPlan('saas');
        $reader = $this->userWithTenantRole('tenant_read_only', $tenant);
        $admin = $this->userWithTenantRole('tenant_admin', $tenant);

        $this->actingAs($reader)->postJson('/api/v1/auth/tokens', ['name' => 'x123', 'abilities' => ['suppliers.read'], 'expires_in_days' => 30])
            ->assertStatus(403);
        $this->actingAs($admin)->postJson('/api/v1/auth/tokens', ['name' => 'x123', 'abilities' => ['users.manage'], 'expires_in_days' => 30])
            ->assertStatus(422);
        $this->actingAs($admin)->postJson('/api/v1/auth/tokens', ['name' => 'x123', 'abilities' => ['suppliers.read'], 'expires_in_days' => 9999])
            ->assertStatus(422);
    }

    public function test_bearer_token_works_only_within_abilities_and_its_tenant(): void
    {
        $tenant = $this->tenantWithPlan('saas', 'A');
        $other = $this->tenantWithPlan('saas', 'B');
        $admin = $this->userWithTenantRole('tenant_admin', $tenant);
        $admin->tenants()->attach($other->id, ['role_id' => Role::where('key', 'tenant_admin')->value('id'), 'is_active' => true]);
        $token = $this->tokenFor($admin, $tenant, ['suppliers.read']);

        $this->bearer($token)->getJson('/api/v1/suppliers')->assertOk();
        $this->bearer($token)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.current_tenant.name', 'A')
            ->assertJsonPath('data.permissions', ['suppliers.read']);
        $this->bearer($token)->getJson('/api/v1/purchase-requests')->assertStatus(403);
        $this->bearer($token)->getJson('/api/v1/auth/tokens')->assertStatus(403);
        // El tenant del token no se puede cambiar.
        $this->bearer($token)->postJson('/api/v1/auth/tenant', ['tenant_public_id' => $other->public_id])->assertStatus(400);
    }

    public function test_expired_revoked_or_orphaned_tokens_are_rejected(): void
    {
        $tenant = $this->tenantWithPlan('saas');
        $admin = $this->userWithTenantRole('tenant_admin', $tenant);
        $second = $this->userWithTenantRole('tenant_admin', $tenant);

        $expired = $this->tokenFor($admin, $tenant, ['suppliers.read'], now()->subMinute());
        $this->bearer($expired)->getJson('/api/v1/suppliers')->assertStatus(401);

        $valid = $this->tokenFor($admin, $tenant, ['suppliers.read']);
        $id = (string) $admin->tokens()->latest('id')->value('id');
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->actingAs($admin)->deleteJson("/api/v1/auth/tokens/{$id}")->assertOk();
        $this->bearer($valid)->getJson('/api/v1/suppliers')->assertStatus(401);

        // Si la persona pierde acceso a la empresa, su token deja de servir.
        $other = $this->tokenFor($second, $tenant, ['suppliers.read']);
        $tenant->users()->updateExistingPivot($second->id, ['is_active' => false]);
        $this->bearer($other)->getJson('/api/v1/suppliers')->assertStatus(403);
    }
}
