<?php

namespace Tests\Feature\Company;

use App\Models\Role;
use App\Models\User;
use Tests\Feature\Crm\CrmTestCase;

/** TD-031: el administrador de la empresa quita personas de su equipo. */
class CompanyRemoveUserTest extends CrmTestCase
{
    public function test_admin_removes_member_and_orphan_account_is_deleted(): void
    {
        $member = $this->userWithTenantRole('tenant_procurement_user', $this->tenantA);
        $member->createToken('x', ['reports.read']);

        $this->actingAs($this->adminA)->deleteJson("/api/v1/company/users/{$member->public_id}")
            ->assertOk()->assertJsonMissing(['public_id' => $member->public_id]);

        $this->assertNull(User::find($member->id), 'Sin otra empresa ni rol TIARE, la cuenta se elimina');
        $this->assertSame($member->name, User::withTrashed()->find($member->id)->name);
        $this->assertSame(0, $member->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.user_removed', 'auditable_id' => $member->public_id]);
    }

    public function test_member_of_another_company_keeps_the_account(): void
    {
        $member = $this->userWithTenantRole('tenant_procurement_user', $this->tenantA);
        $member->tenants()->attach($this->tenantB->id, ['role_id' => Role::where('key', 'tenant_read_only')->value('id'), 'is_active' => true]);

        $this->actingAs($this->adminA)->deleteJson("/api/v1/company/users/{$member->public_id}")->assertOk();

        $this->assertNotNull(User::find($member->id));
        $this->assertFalse($member->fresh()->belongsToTenant($this->tenantA));
        $this->assertTrue($member->fresh()->belongsToTenant($this->tenantB));
    }

    public function test_guards_self_last_admin_other_tenant_and_permission(): void
    {
        // A sí mismo / último administrador.
        $this->actingAs($this->adminA)->deleteJson("/api/v1/company/users/{$this->adminA->public_id}")->assertStatus(422);
        // Persona de otra empresa: no existe para A.
        $this->actingAs($this->adminA)->deleteJson("/api/v1/company/users/{$this->adminB->public_id}")->assertNotFound();
        // Sin permiso users.manage.
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $this->actingAs($reader)->deleteJson("/api/v1/company/users/{$this->adminA->public_id}")->assertForbidden();

        // Con un segundo administrador, uno puede quitar al otro.
        $second = $this->userWithTenantRole('tenant_admin', $this->tenantA);
        $this->actingAs($second)->deleteJson("/api/v1/company/users/{$this->adminA->public_id}")->assertOk();
        $this->actingAs($second)->deleteJson("/api/v1/company/users/{$second->public_id}")->assertStatus(422);
    }
}
