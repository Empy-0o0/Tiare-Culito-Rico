<?php

namespace Tests;

use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ninguna prueba puede llamar a internet.
        Http::preventStrayRequests();
        Sleep::fake();
    }

    /** @return array<string, mixed> */
    protected function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/Fixtures/mercado_publico/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function seedRoles(): void
    {
        $this->seed([RolesAndPermissionsSeeder::class, PlansSeeder::class]);
    }

    protected function userWithTenantRole(string $roleKey, ?Tenant $tenant = null): User
    {
        $tenant ??= Tenant::create(['name' => 'Tenant de prueba', 'status' => 'ACTIVE', 'is_demo' => true]);
        $this->ensurePlan($tenant);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['role_id' => Role::where('key', $roleKey)->value('id'), 'is_active' => true]);

        return $user;
    }

    /** Las empresas de prueba usan por defecto el plan completo (TD-024). */
    protected function ensurePlan(Tenant $tenant, string $planKey = 'saas'): void
    {
        if (! $tenant->subscriptions()->exists()) {
            $tenant->subscriptions()->create(['plan_id' => Plan::where('key', $planKey)->value('id'), 'status' => 'active', 'starts_on' => '2020-01-01']);
            $tenant->forgetSubscriptionCache();
        }
    }

    protected function platformUser(string $roleKey): User
    {
        return User::factory()->create(['platform_role_id' => Role::where('key', $roleKey)->value('id')]);
    }
}
