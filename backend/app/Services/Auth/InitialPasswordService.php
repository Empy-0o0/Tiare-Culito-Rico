<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\InitialPasswordGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Núcleo común de la contraseña de inicio (TD-026/TD-027), usado por el administrador TIARE
 * y por el administrador de una empresa. Quien llama valida antes si puede hacerlo.
 *
 * - Genera la contraseña en el servidor y la devuelve una sola vez (solo se guarda su hash).
 * - Marca la cuenta para cambiarla al ingresar.
 * - Cierra sus sesiones web y revoca sus tokens API.
 */
class InitialPasswordService
{
    public function issue(User $user): string
    {
        $password = InitialPasswordGenerator::generate();
        DB::transaction(function () use ($user, $password) {
            $user->forceFill([
                'password' => $password,
                'must_change_password' => true,
                'remember_token' => Str::random(60),
            ])->save();
            $this->closeAccess($user);
        });

        return $password;
    }

    /** Cierra sesiones web (driver database) y revoca tokens API de la cuenta. */
    public function closeAccess(User $user): void
    {
        $user->tokens()->delete();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
    }
}
