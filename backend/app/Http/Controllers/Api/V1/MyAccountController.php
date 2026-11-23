<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "Mi cuenta": la persona cambia su propia contraseña (TD-026).
 * - Exige la contraseña actual (también cuando es una contraseña de inicio).
 * - Política: mínimo 10 caracteres, letras y números, distinta de la actual.
 * - Cierra las demás sesiones de la cuenta; la sesión actual se renueva.
 * - Solo desde la sesión web (un token API no puede cambiar la contraseña).
 */
class MyAccountController extends Controller
{
    public function changePassword(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        if ($user->currentAccessToken() instanceof PersonalAccessToken || ! $request->hasSession()) {
            return ApiResponse::error('FORBIDDEN', 'La contraseña solo se cambia desde la aplicación web.', 403);
        }

        $v = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'confirmed', 'max:200', PasswordRule::min(10)->letters()->numbers()],
        ], [
            'password.confirmed' => 'La confirmación no coincide con la nueva contraseña.',
        ]);

        if (! Hash::check($v['current_password'], $user->password)) {
            $audit->log('auth.password_change_failed', $user->getKey(), 'user', $user->public_id);

            return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['current_password' => ['La contraseña actual no es correcta.']]);
        }
        if (Hash::check($v['password'], $user->password)) {
            return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['password' => ['La nueva contraseña debe ser distinta de la actual.']]);
        }

        $wasInitial = (bool) $user->must_change_password;
        $user->forceFill([
            'password' => $v['password'],
            'must_change_password' => false,
            'password_changed_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        // Cierra las demás sesiones de la cuenta y renueva la actual.
        $current = $request->session()->getId();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->where('id', '!=', $current)->delete();
        }
        $request->session()->regenerate();

        $audit->log('auth.password_changed', $user->getKey(), 'user', $user->public_id, ['was_initial' => $wasInitial]);

        return ApiResponse::ok([
            'message' => 'Contraseña actualizada. Cerramos tus otras sesiones abiertas.',
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
        ]);
    }
}
