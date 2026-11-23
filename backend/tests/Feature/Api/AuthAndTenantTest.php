<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_health_is_public_and_has_no_secrets(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
        $this->assertStringNotContainsString((string) config('mercado_publico.ticket'), $response->getContent());
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->getJson('/api/v1/public-procurement/compra-agil')->assertStatus(401);
    }

    public function test_login_returns_profile_with_tenant_and_permissions(): void
    {
        $user = $this->userWithTenantRole('tenant_read_only');
        $user->update(['password' => 'secreto-local-123']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secreto-local-123'], ['Referer' => 'http://localhost:5173/login'])
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.current_tenant.name', 'Tenant de prueba')
            ->assertJsonMissingPath('data.user.id')
            ->assertJsonFragment(['public_procurement.read']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_login_with_wrong_password_fails_and_is_audited_without_email(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'incorrecta'], ['Referer' => 'http://localhost:5173/login'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $log = AuditLog::where('action', 'auth.login_failed')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($user->email, json_encode($log->metadata));
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create(['is_active' => false, 'password' => 'secreto-local-123']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secreto-local-123'], ['Referer' => 'http://localhost:5173/login'])->assertStatus(422);
    }

    public function test_login_from_non_spa_origin_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'secreto-local-123']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secreto-local-123'], ['Referer' => 'https://otro-sitio.test'])
            ->assertStatus(400);
    }

    public function test_user_cannot_switch_to_foreign_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'status' => 'ACTIVE']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'status' => 'ACTIVE']);
        $user = $this->userWithTenantRole('tenant_admin', $tenantA);

        $spa = ['Referer' => 'http://localhost:5173/'];
        $this->actingAs($user)->postJson('/api/v1/auth/tenant', ['tenant_public_id' => $tenantB->public_id], $spa)
            ->assertStatus(404);

        $this->actingAs($user)->postJson('/api/v1/auth/tenant', ['tenant_public_id' => $tenantA->public_id], $spa)
            ->assertOk()->assertJsonPath('data.current_tenant.public_id', $tenantA->public_id);
    }

    public function test_permissions_come_from_role_in_active_tenant_only(): void
    {
        $tenantA = Tenant::create(['name' => 'Empresa A', 'status' => 'ACTIVE']);
        $tenantB = Tenant::create(['name' => 'Empresa B', 'status' => 'ACTIVE']);
        $user = $this->userWithTenantRole('tenant_approver', $tenantA);

        $this->assertTrue($user->hasPermission('purchase_requests.approve', $tenantA));
        $this->assertFalse($user->hasPermission('purchase_requests.approve', $tenantB));
        $this->assertFalse($user->hasPermission('public_procurement.sync', $tenantA));
    }

    public function test_suspended_tenant_is_not_resolved(): void
    {
        $tenant = Tenant::create(['name' => 'Suspendida', 'status' => 'SUSPENDED']);
        $user = $this->userWithTenantRole('tenant_admin', $tenant);

        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.current_tenant', null);
        $this->actingAs($user)->getJson('/api/v1/public-procurement/compra-agil')->assertStatus(409)->assertJsonPath('error.code', 'NO_ACTIVE_TENANT');
    }
}
