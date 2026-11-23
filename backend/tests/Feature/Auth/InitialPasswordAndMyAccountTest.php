<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\InitialPasswordGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** TD-026: contraseña de inicio generada por el admin y cambio de contraseña propio. */
class InitialPasswordAndMyAccountTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = ['Referer' => 'http://localhost:5173/'];

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

    public function test_generator_meets_policy(): void
    {
        foreach (range(1, 50) as $_) {
            $p = InitialPasswordGenerator::generate();
            $this->assertMatchesRegularExpression('/^[A-Za-z2-9]{4}(-[A-Za-z2-9]{4}){3}-\d{2}$/', $p);
            $this->assertMatchesRegularExpression('/[a-z]/', $p);
            $this->assertMatchesRegularExpression('/[A-Z]/', $p);
        }
        $this->assertNotSame(InitialPasswordGenerator::generate(), InitialPasswordGenerator::generate());
    }

    public function test_admin_creates_account_with_initial_password_shown_once(): void
    {
        $res = $this->actingAs($this->admin)->postJson('/api/v1/admin/users', [
            'name' => 'Persona Ficticia', 'email' => 'p@ejemplo.test', 'credential' => 'password',
            'memberships' => [['tenant_public_id' => $this->tenant->public_id, 'role_key' => 'tenant_read_only']],
        ])->assertCreated()->assertJsonPath('data.must_change_password', true)->assertJsonPath('data.link_sent', false);

        $initial = $res->json('data.initial_password');
        $user = User::where('email', 'p@ejemplo.test')->first();
        $this->assertTrue(Hash::check($initial, $user->password));
        $this->assertDatabaseMissing('audit_logs', ['metadata' => json_encode($initial)]);
        Notification::assertNothingSent();

        // No vuelve a aparecer en el listado.
        $this->actingAs($this->admin)->getJson('/api/v1/admin/users')->assertOk()->assertJsonMissing(['initial_password' => $initial]);
    }

    public function test_regenerating_initial_password_closes_access(): void
    {
        $user = $this->userWithTenantRole('tenant_admin', $this->tenant);
        $user->createToken('x', ['suppliers.read']);
        // En pruebas el driver de sesión es "array"; se fuerza "database" para verificar el cierre de sesiones.
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'sesion-ficticia', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $p = $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$user->public_id}/initial-password")->assertOk()->json('data.initial_password');

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($p, $user->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-ficticia']);

        // No para uno mismo.
        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$this->admin->public_id}/initial-password")->assertStatus(422);
        // Un usuario de empresa no puede.
        $this->actingAs($user)->postJson("/api/v1/admin/users/{$this->admin->public_id}/initial-password")->assertStatus(403);
    }

    public function test_pending_initial_password_blocks_everything_but_changing_it(): void
    {
        $user = $this->userWithTenantRole('tenant_admin', $this->tenant);
        $user->forceFill(['password' => 'Inicio-1234-abcd', 'must_change_password' => true])->save();

        $this->actingAs($user)->getJson('/api/v1/suppliers')->assertStatus(403)->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);

        $this->actingAs($user)->putJson('/api/v1/auth/password', ['current_password' => 'Inicio-1234-abcd', 'password' => 'MiClaveNueva2026', 'password_confirmation' => 'MiClaveNueva2026'], self::SPA)
            ->assertOk();
        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->actingAs($user)->getJson('/api/v1/suppliers')->assertOk();
    }

    public function test_change_password_validation(): void
    {
        $user = $this->userWithTenantRole('tenant_read_only', $this->tenant);
        $user->forceFill(['password' => 'ClaveActual2026'])->save();
        $put = fn (array $b) => $this->actingAs($user)->putJson('/api/v1/auth/password', $b, self::SPA);

        $put(['current_password' => 'incorrecta', 'password' => 'OtraClave2026x', 'password_confirmation' => 'OtraClave2026x'])->assertStatus(422)->assertJsonValidationErrors(['current_password'], 'error.details');
        $put(['current_password' => 'ClaveActual2026', 'password' => 'soloLetrasLargas', 'password_confirmation' => 'soloLetrasLargas'])->assertStatus(422);
        $put(['current_password' => 'ClaveActual2026', 'password' => 'OtraClave2026x', 'password_confirmation' => 'distinta2026x'])->assertStatus(422);
        $put(['current_password' => 'ClaveActual2026', 'password' => 'ClaveActual2026', 'password_confirmation' => 'ClaveActual2026'])->assertStatus(422);
        $put(['current_password' => 'ClaveActual2026', 'password' => 'OtraClave2026x', 'password_confirmation' => 'OtraClave2026x'])->assertOk();
        $this->assertTrue(Hash::check('OtraClave2026x', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed']);
        // Límite: 5 intentos por minuto por cuenta.
        $put(['current_password' => 'x', 'password' => 'OtraClave2026y', 'password_confirmation' => 'OtraClave2026y'])->assertStatus(429);
    }

    public function test_api_token_cannot_change_password(): void
    {
        $user = $this->userWithTenantRole('tenant_admin', $this->tenant);
        $t = $user->createToken('x', ['suppliers.read']);
        $t->accessToken->forceFill(['tenant_id' => $this->tenant->id])->save();
        $this->withHeader('Authorization', 'Bearer '.$t->plainTextToken)
            ->putJson('/api/v1/auth/password', ['current_password' => 'password', 'password' => 'OtraClave2026x', 'password_confirmation' => 'OtraClave2026x'])
            ->assertStatus(403);
    }
}
