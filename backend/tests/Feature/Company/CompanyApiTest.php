<?php

namespace Tests\Feature\Company;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Crm\CrmTestCase;

class CompanyApiTest extends CrmTestCase
{
    public function test_admin_sees_and_updates_company_data(): void
    {
        $this->actingAs($this->adminA)->getJson('/api/v1/company')
            ->assertOk()
            ->assertJsonPath('data.name', 'Empresa A')
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.can_manage_users', true);

        $this->actingAs($this->adminA)->patchJson('/api/v1/company', ['name' => 'Empresa A SpA', 'legal_name' => 'Empresa A Sociedad por Acciones', 'rut' => '123456785'])
            ->assertOk()
            ->assertJsonPath('data.rut', '12.345.678-5')
            ->assertJsonPath('data.legal_name', 'Empresa A Sociedad por Acciones');

        $this->assertDatabaseHas('audit_logs', ['action' => 'company.updated']);
    }

    public function test_company_rut_is_validated_and_unique(): void
    {
        $this->actingAs($this->adminA)->patchJson('/api/v1/company', ['rut' => '12.345.678-9'])->assertStatus(422);

        $this->tenantB->update(['rut' => '12.345.678-5']);
        $this->actingAs($this->adminA)->patchJson('/api/v1/company', ['rut' => '12345678-5'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.rut.0', 'Otra empresa ya usa este RUT.');
    }

    public function test_read_only_user_cannot_manage_company_or_users(): void
    {
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);

        $this->actingAs($reader)->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.can_manage', false);
        $this->actingAs($reader)->patchJson('/api/v1/company', ['name' => 'X'])->assertForbidden();
        $this->actingAs($reader)->getJson('/api/v1/company/users')->assertForbidden();
        $this->actingAs($reader)->postJson('/api/v1/company/users', ['name' => 'X', 'email' => 'x@x.test', 'role_key' => 'tenant_admin'])->assertForbidden();
    }

    public function test_users_list_only_includes_current_tenant_members(): void
    {
        $this->actingAs($this->adminA)->getJson('/api/v1/company/users')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $this->adminA->public_id)
            ->assertJsonPath('data.0.role.key', 'tenant_admin')
            ->assertJsonMissing(['public_id' => $this->adminB->public_id]);
    }

    public function test_roles_endpoint_lists_only_tenant_roles(): void
    {
        $keys = collect($this->actingAs($this->adminA)->getJson('/api/v1/company/roles')->assertOk()->json('data'))->pluck('key');

        $this->assertContains('tenant_admin', $keys);
        $this->assertNotContains('platform_super_admin', $keys);
    }

    public function test_inviting_a_new_person_creates_account_and_sends_link(): void
    {
        Notification::fake();

        $this->actingAs($this->adminA)->postJson('/api/v1/company/users', ['name' => 'Persona Nueva', 'email' => 'Nueva@Ejemplo.test', 'role_key' => 'tenant_procurement_user'])
            ->assertCreated()
            ->assertJsonPath('data.new_account', true)
            ->assertJsonPath('data.link_sent', true);

        $user = User::where('email', 'nueva@ejemplo.test')->firstOrFail();
        $this->assertTrue($this->tenantA->users()->whereKey($user->id)->exists());
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            // Token y correo solo en el fragmento (#), nunca en la query.
            return str_starts_with($url, 'http://localhost:5173/restablecer#token=') && ! str_contains($url, '?');
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.user_invited']);
    }

    public function test_inviting_existing_account_grants_access_without_email(): void
    {
        Notification::fake();

        $this->actingAs($this->adminA)->postJson('/api/v1/company/users', ['name' => 'Otro nombre', 'email' => $this->adminB->email, 'role_key' => 'tenant_read_only'])
            ->assertCreated()
            ->assertJsonPath('data.new_account', false);

        Notification::assertNothingSent();
        $this->assertTrue($this->tenantA->users()->whereKey($this->adminB->id)->exists());
        $this->assertSame($this->adminB->name, $this->adminB->fresh()->name); // no se pisa el nombre existente
    }

    public function test_invite_rejects_platform_roles_and_duplicates(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/company/users', ['name' => 'X Y', 'email' => 'xy@x.test', 'role_key' => 'platform_super_admin'])->assertStatus(422);
        $this->actingAs($this->adminA)->postJson('/api/v1/company/users', ['name' => 'X Y', 'email' => $this->adminA->email, 'role_key' => 'tenant_read_only'])->assertStatus(422);
    }

    public function test_admin_changes_role_and_deactivates_member(): void
    {
        $member = $this->userWithTenantRole('tenant_read_only', $this->tenantA);

        $this->actingAs($this->adminA)->patchJson("/api/v1/company/users/{$member->public_id}", ['role_key' => 'tenant_approver'])
            ->assertOk();
        $this->assertSame(Role::where('key', 'tenant_approver')->value('id'), $this->tenantA->users()->whereKey($member->id)->first()->pivot->role_id);

        $this->actingAs($this->adminA)->patchJson("/api/v1/company/users/{$member->public_id}", ['is_active' => false])->assertOk();
        $this->actingAs($member->fresh())->getJson('/api/v1/customers')->assertStatus(409); // sin membresía activa: no tiene empresa

        $this->assertSame(2, AuditLog::where('action', 'company.user_updated')->count());
    }

    public function test_cannot_change_own_membership_or_leave_company_without_admin(): void
    {
        $this->actingAs($this->adminA)->patchJson("/api/v1/company/users/{$this->adminA->public_id}", ['is_active' => false])
            ->assertStatus(422);

        // Segundo admin intenta degradar al único otro admin: queda él como admin, se permite.
        $admin2 = $this->userWithTenantRole('tenant_admin', $this->tenantA);
        $this->actingAs($admin2)->patchJson("/api/v1/company/users/{$this->adminA->public_id}", ['role_key' => 'tenant_read_only'])->assertOk();

        // Ahora admin2 es el único admin; nadie más puede quitarlo (y él no puede tocarse a sí mismo).
        $this->actingAs($admin2)->patchJson("/api/v1/company/users/{$admin2->public_id}", ['role_key' => 'tenant_read_only'])->assertStatus(422);
    }

    public function test_cannot_update_member_of_another_tenant(): void
    {
        $this->actingAs($this->adminA)->patchJson("/api/v1/company/users/{$this->adminB->public_id}", ['is_active' => false])->assertNotFound();
    }

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nadie@ejemplo.test'])
            ->assertOk()
            ->assertJsonPath('data.message', 'Si el correo está registrado, recibirás un enlace para definir una nueva contraseña.');

        $token = Password::broker()->createToken($this->adminA);
        $this->postJson('/api/v1/auth/reset-password', ['token' => 'malo', 'email' => $this->adminA->email, 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $this->adminA->email, 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $this->adminA->email, 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])
            ->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NuevaClave2026', $this->adminA->fresh()->password));
    }
}
