<?php

namespace Tests\Feature\Company;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** TD-027: contraseña de inicio desde la configuración de la empresa. */
class CompanyInitialPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Notification::fake();
        $this->tenant = Tenant::create(['name' => 'Empresa Ficticia', 'status' => 'ACTIVE']);
        $this->admin = $this->userWithTenantRole('tenant_admin', $this->tenant);
    }

    public function test_invite_new_person_with_initial_password(): void
    {
        $res = $this->actingAs($this->admin)->postJson('/api/v1/company/users', [
            'name' => 'Persona Nueva', 'email' => 'nueva@ejemplo.test', 'role_key' => 'tenant_read_only', 'credential' => 'password',
        ])->assertCreated()->assertJsonPath('data.link_sent', false);

        $user = User::where('email', 'nueva@ejemplo.test')->first();
        $this->assertTrue(Hash::check($res->json('data.initial_password'), $user->password));
        $this->assertTrue($user->must_change_password);
        Notification::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.initial_password_issued']);
    }

    public function test_existing_account_keeps_its_password_when_invited(): void
    {
        $other = Tenant::create(['name' => 'Otra Empresa', 'status' => 'ACTIVE']);
        $existing = $this->userWithTenantRole('tenant_read_only', $other);
        $hash = $existing->password;

        $this->actingAs($this->admin)->postJson('/api/v1/company/users', [
            'name' => 'Persona Existente', 'email' => $existing->email, 'role_key' => 'tenant_read_only', 'credential' => 'password',
        ])->assertCreated()->assertJsonPath('data.new_account', false)->assertJsonPath('data.initial_password', null);

        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertFalse($existing->fresh()->must_change_password);
    }

    public function test_regenerate_only_for_people_exclusive_to_the_company(): void
    {
        $own = $this->userWithTenantRole('tenant_read_only', $this->tenant);
        $own->createToken('x', ['suppliers.read']);

        $p = $this->actingAs($this->admin)->postJson("/api/v1/company/users/{$own->public_id}/initial-password")->assertOk()->json('data.initial_password');
        $this->assertTrue(Hash::check($p, $own->fresh()->password));
        $this->assertSame(0, $own->tokens()->count());

        // Persona que también pertenece a otra empresa.
        $shared = $this->userWithTenantRole('tenant_read_only', $this->tenant);
        $other = Tenant::create(['name' => 'Otra', 'status' => 'ACTIVE']);
        $shared->tenants()->attach($other->id, ['role_id' => Role::where('key', 'tenant_read_only')->value('id'), 'is_active' => true]);
        $this->actingAs($this->admin)->postJson("/api/v1/company/users/{$shared->public_id}/initial-password")->assertStatus(422);

        // Personal TIARE con acceso a la empresa.
        $staff = $this->platformUser('tiare_admin');
        $staff->tenants()->attach($this->tenant->id, ['role_id' => Role::where('key', 'tenant_admin')->value('id'), 'is_active' => true]);
        $this->actingAs($this->admin)->postJson("/api/v1/company/users/{$staff->public_id}/initial-password")->assertStatus(422);

        // Uno mismo, persona ajena y sin permiso.
        $this->actingAs($this->admin)->postJson("/api/v1/company/users/{$this->admin->public_id}/initial-password")->assertStatus(422);
        $foreign = $this->userWithTenantRole('tenant_read_only', $other);
        $this->actingAs($this->admin)->postJson("/api/v1/company/users/{$foreign->public_id}/initial-password")->assertStatus(404);
        $this->actingAs($own->fresh()->forceFill(['must_change_password' => false]))->postJson("/api/v1/company/users/{$this->admin->public_id}/initial-password")->assertStatus(403);

        // El listado indica a quién se le puede generar y por qué no a los demás.
        $list = collect($this->actingAs($this->admin)->getJson('/api/v1/company/users')->assertOk()->json('data'))->keyBy('public_id');
        $this->assertNull($list[$own->public_id]['initial_password_blocker']);
        $this->assertTrue($list[$own->public_id]['must_change_password']);
        $this->assertStringContainsString('otra empresa', $list[$shared->public_id]['initial_password_blocker']);
    }
}
