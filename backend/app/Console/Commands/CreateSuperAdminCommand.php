<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\InitialPasswordService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Crea (o promueve) una cuenta de superadministrador de plataforma (TD-028).
 *
 * La contraseña de inicio se imprime UNA vez en la terminal de quien ejecuta el comando,
 * nunca pasa por la aplicación web, logs ni auditoría, y debe cambiarse en el primer ingreso.
 *
 *   php artisan tiare:superadmin persona@dominio.cl --name="Nombre Apellido"
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'tiare:superadmin
        {email : Correo de acceso de la persona}
        {--name= : Nombre completo (obligatorio si la cuenta no existe)}
        {--reset-password : Si la cuenta ya existe, genera una nueva contraseña de inicio}';

    protected $description = 'Crea o promueve una cuenta de superadministrador de plataforma con contraseña de inicio';

    public function handle(InitialPasswordService $passwords, AuditLogger $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) $this->option('name'));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:190']])->fails()) {
            $this->error('Correo no válido.');

            return self::FAILURE;
        }

        $roleId = Role::query()->where('key', 'platform_super_admin')->value('id');
        if (! $roleId) {
            $this->error('No existe el rol platform_super_admin. Ejecuta primero: php artisan db:seed --class=RolesAndPermissionsSeeder');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user && mb_strlen($name) < 2) {
            $this->error('La cuenta no existe: indica el nombre con --name="Nombre Apellido".');

            return self::FAILURE;
        }

        $created = false;
        $password = null;
        DB::transaction(function () use (&$user, &$created, &$password, $email, $name, $roleId, $passwords) {
            if (! $user) {
                $user = User::create(['name' => $name, 'email' => $email, 'password' => Str::password(40), 'is_active' => true]);
                $created = true;
            }
            $user->forceFill(['platform_role_id' => $roleId, 'is_active' => true] + ($name !== '' ? ['name' => $name] : []))->save();

            if ($created || $this->option('reset-password')) {
                $password = $passwords->issue($user);
            }
        });

        $audit->log($created ? 'admin.super_admin_created' : 'admin.super_admin_promoted', null, 'user', $user->public_id, ['via' => 'console']);

        $this->info($created ? "Superadministrador creado: {$user->name} <{$user->email}>" : "La cuenta {$user->email} ahora es superadministradora.");
        if ($password) {
            $this->newLine();
            $this->line('  Contraseña de inicio (se muestra solo esta vez):');
            $this->newLine();
            $this->line("      {$password}");
            $this->newLine();
            $this->warn('  Guárdala ahora. Al ingresar, TIARE pedirá cambiarla. No la envíes por correo ni chat.');
        } else {
            $this->line('Conserva su contraseña actual. Usa --reset-password para generar una de inicio.');
        }

        return self::SUCCESS;
    }
}
