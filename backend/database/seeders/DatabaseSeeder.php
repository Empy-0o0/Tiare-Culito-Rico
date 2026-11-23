<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Roles/permisos + tenant demo + administrador local.
     * Credenciales desde SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD (backend/.env); nunca en el código.
     */
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, PlansSeeder::class, UnitsOfMeasureSeeder::class]);

        $email = env('SEED_ADMIN_EMAIL');
        $password = env('SEED_ADMIN_PASSWORD');
        if (! $email || ! $password) {
            if (app()->environment('production')) {
                throw new RuntimeException('Define SEED_ADMIN_EMAIL y SEED_ADMIN_PASSWORD para crear el administrador.');
            }
            $this->command?->warn('SEED_ADMIN_EMAIL/SEED_ADMIN_PASSWORD no definidos: no se crea usuario administrador.');

            return;
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['name' => 'Empresa demostrativa (datos ficticios)'],
            ['status' => 'ACTIVE', 'is_demo' => true],
        );

        // La empresa demostrativa usa el plan completo para mostrar todos los módulos.
        if (! $tenant->subscriptions()->exists()) {
            $tenant->subscriptions()->create([
                'plan_id' => Plan::query()->where('key', 'saas')->value('id'),
                'status' => 'active',
                'starts_on' => now('America/Santiago')->toDateString(),
                'notes' => 'Empresa demostrativa (datos ficticios).',
            ]);
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill([
            'name' => 'Administrador TIARE',
            'password' => $password,
            'platform_role_id' => Role::query()->where('key', 'tiare_admin')->value('id'),
            'is_active' => true,
        ])->save();

        $user->tenants()->syncWithoutDetaching([
            $tenant->id => ['role_id' => Role::query()->where('key', 'tenant_admin')->value('id'), 'is_active' => true],
        ]);

        if (! app()->environment('production')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
