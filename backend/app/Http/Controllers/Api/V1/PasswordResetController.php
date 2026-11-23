<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Definir o recuperar contraseña con enlace por correo (broker de Laravel, tokens de un solo uso
 * con vencimiento). Se usa para invitaciones y para "olvidé mi contraseña".
 */
class PasswordResetController extends Controller
{
    /** Respuesta siempre igual, exista o no la cuenta (no revela qué correos están registrados). */
    public function forgot(Request $request, AuditLogger $audit): JsonResponse
    {
        $v = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $status = Password::broker()->sendResetLink(['email' => Str::lower($v['email'])]);
        $audit->log('auth.password_reset_requested', metadata: ['email_hash' => hash('sha256', Str::lower($v['email'])), 'sent' => $status === Password::RESET_LINK_SENT]);

        return ApiResponse::ok(['message' => 'Si el correo está registrado, recibirás un enlace para definir una nueva contraseña.']);
    }

    public function reset(Request $request, AuditLogger $audit): JsonResponse
    {
        $v = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', 'max:200', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::broker()->reset(
            ['email' => Str::lower($v['email']), 'token' => $v['token'], 'password' => $v['password'], 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password) use ($audit): void {
                // La persona eligió su propia contraseña: se libera una contraseña de inicio pendiente (TD-026).
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'must_change_password' => false, 'password_changed_at' => now()])->save();
                event(new PasswordReset($user));
                $audit->log('auth.password_reset', $user->getKey(), 'user', $user->public_id);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error('INVALID_RESET_TOKEN', 'El enlace no es válido o venció. Solicita uno nuevo desde "¿Olvidaste tu contraseña?".', 422);
        }

        return ApiResponse::ok(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
    }
}
