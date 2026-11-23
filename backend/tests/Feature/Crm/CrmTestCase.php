<?php

namespace Tests\Feature\Crm;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\UnitsOfMeasureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class CrmTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    protected User $adminA;

    protected User $adminB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seed(UnitsOfMeasureSeeder::class);
        $this->tenantA = Tenant::create(['name' => 'Empresa A', 'status' => 'ACTIVE']);
        $this->tenantB = Tenant::create(['name' => 'Empresa B', 'status' => 'ACTIVE']);
        $this->adminA = $this->userWithTenantRole('tenant_admin', $this->tenantA);
        $this->adminB = $this->userWithTenantRole('tenant_admin', $this->tenantB);
    }

    /** @return array<string, mixed> */
    protected function customerPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'organization' => ['legal_name' => 'Comercial Ficticia SpA', 'rut' => '12.345.678-5', 'kind' => 'SME', 'region_code' => 13, 'email' => 'contacto@ficticia.test'],
            'contact' => ['full_name' => 'Persona Ficticia', 'email' => 'persona@ficticia.test'],
            'plan' => 'Growth',
        ], $overrides);
    }
}
