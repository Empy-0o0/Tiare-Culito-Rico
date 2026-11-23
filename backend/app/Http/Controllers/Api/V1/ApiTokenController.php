<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\NoActiveTenantException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tokens de acceso API (Sanctum, encabezado Authorization: Bearer) para integraciones de la empresa.
 *
 * - Cada token queda limitado a la empresa activa al emitirlo y a un subconjunto de los permisos
 *   de quien lo emite (abilities). El plan de la empresa se sigue aplicando en cada request.
 * - El valor solo se muestra una vez; en base de datos Sanctum guarda únicamente su hash SHA-256.
 * - Siempre vencen. Solo se emiten y revocan desde la sesión web, nunca usando otro token.
 */
class ApiTokenController extends Controller
{
    public const EXPIRY_DAYS = [7, 30, 90, 180, 365];

    /** Permisos que un token nunca puede llevar. */
    private const FORBIDDEN_PREFIXES = ['api_tokens.', 'users.', 'platform.', 'company.'];

    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->requireSession($request)) {
            return $denied;
        }
        $tenant = $this->current->get();
        if ($tenant === null) {
            throw new NoActiveTenantException;
        }

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', $request->user()->getMorphClass())
            ->where('tokenable_id', $request->user()->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->latest('id')
            ->get()
            ->map(fn (PersonalAccessToken $t) => $this->present($t))
            ->values();

        return ApiResponse::ok($tokens, [
            'available_abilities' => $this->grantable($request->user()),
            'expiry_days' => self::EXPIRY_DAYS,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        if ($denied = $this->requireSession($request)) {
            return $denied;
        }
        $tenant = $this->current->get();
        if ($tenant === null) {
            throw new NoActiveTenantException;
        }
        $user = $request->user();

        $v = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'abilities' => ['required', 'array', 'min:1', 'max:60'],
            'abilities.*' => ['string', 'distinct', Rule::in($this->grantable($user))],
            'expires_in_days' => ['required', 'integer', Rule::in(self::EXPIRY_DAYS)],
        ], [
            'abilities.*.in' => 'Solo puedes otorgar permisos que tienes en esta empresa.',
        ]);

        $new = $user->createToken(trim($v['name']), array_values($v['abilities']), now()->addDays((int) $v['expires_in_days']));
        $new->accessToken->forceFill(['tenant_id' => $tenant->getKey()])->save();

        $audit->log('api_token.created', $user->getKey(), 'api_token', (string) $new->accessToken->getKey(), [
            'abilities' => $v['abilities'],
            'expires_in_days' => (int) $v['expires_in_days'],
        ]);

        return ApiResponse::ok([
            'token' => $this->present($new->accessToken->fresh()),
            // Única vez que se entrega el valor. No se puede recuperar después.
            'plain_text_token' => $new->plainTextToken,
        ], status: 201);
    }

    public function destroy(Request $request, string $id, AuditLogger $audit): JsonResponse
    {
        if ($denied = $this->requireSession($request)) {
            return $denied;
        }
        $token = PersonalAccessToken::query()
            ->whereKey((int) $id)
            ->where('tokenable_type', $request->user()->getMorphClass())
            ->where('tokenable_id', $request->user()->getKey())
            ->where('tenant_id', $this->current->id())
            ->first();
        if (! $token) {
            return ApiResponse::error('RESOURCE_NOT_FOUND', 'Recurso no encontrado.', 404);
        }

        $token->delete();
        $audit->log('api_token.revoked', $request->user()->getKey(), 'api_token', (string) $id);

        return ApiResponse::ok(['revoked' => true]);
    }

    /** @return list<string> */
    private function grantable(User $user): array
    {
        return array_values(array_filter(
            $user->permissionsFor($this->current->get()),
            fn (string $p) => ! collect(self::FORBIDDEN_PREFIXES)->contains(fn ($prefix) => str_starts_with($p, $prefix)),
        ));
    }

    private function requireSession(Request $request): ?JsonResponse
    {
        if ($request->user()->currentAccessToken() instanceof PersonalAccessToken) {
            return ApiResponse::error('FORBIDDEN', 'Los tokens solo se administran desde la aplicación web.', 403);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function present(PersonalAccessToken $t): array
    {
        return [
            'id' => (string) $t->getKey(),
            'name' => $t->name,
            'abilities' => $t->abilities,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'expired' => $t->expires_at !== null && $t->expires_at->isPast(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
