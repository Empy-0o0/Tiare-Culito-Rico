<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Elimina una cuenta conservando el historial (TD-031).
 *
 * - Cierra sesiones y revoca tokens; quita todas sus empresas.
 * - Libera el correo (se reemplaza por una dirección no entregable) para que pueda reutilizarse.
 * - Conserva el nombre: aprobaciones, órdenes y auditoría siguen mostrando quién actuó.
 * - Borrado lógico: la cuenta no inicia sesión ni aparece en listas.
 */
class AccountRetirementService
{
    public function __construct(private readonly InitialPasswordService $initialPasswords) {}

    public function retire(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->initialPasswords->closeAccess($user);
            $user->tenants()->detach();
            $user->forceFill([
                'email' => 'eliminada+'.strtolower($user->public_id).'@cuentas.invalid',
                'is_active' => false,
                'platform_role_id' => null,
                'remember_token' => null,
            ])->save();
            $user->delete();
        });
    }
}
