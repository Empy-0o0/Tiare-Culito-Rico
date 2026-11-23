<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\PlanModules;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'password', 'platform_role_id', 'is_active'];

    protected $hidden = ['id', 'password', 'remember_token', 'platform_role_id'];

    /** Coincide con el default de la columna para modelos recién creados. */
    protected $attributes = ['is_active' => true];

    /** @var array<string, list<string>> caché por request: tenant_id => permisos */
    private array $permissionCache = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
        ];
    }

    public function platformRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'platform_role_id');
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withPivot(['role_id', 'is_active', 'approval_limit_clp'])->withTimestamps();
    }

    /** Tenants activos a los que pertenece el usuario. */
    public function activeTenants(): BelongsToMany
    {
        return $this->tenants()->wherePivot('is_active', true)->where('tenants.status', 'ACTIVE');
    }

    public function belongsToTenant(Tenant $tenant): bool
    {
        return $this->activeTenants()->whereKey($tenant->getKey())->exists();
    }

    /**
     * Empresas en las que la cuenta puede operar (TD-032): el superadministrador y el administrador
     * TIARE operan en cualquier empresa activa, sin necesitar membresía; el resto, solo en las suyas.
     *
     * @return Builder<Tenant>|BelongsToMany<Tenant, $this>
     */
    public function accessibleTenants(): Builder|BelongsToMany
    {
        return $this->is_active && $this->isPlatformAdmin()
            ? Tenant::query()->where('tenants.status', 'ACTIVE')
            : $this->activeTenants();
    }

    /**
     * Permisos efectivos = permisos del rol de plataforma ∪ permisos del rol en el tenant activo.
     *
     * @return list<string>
     */
    public function permissionsFor(?Tenant $tenant): array
    {
        $cacheKey = (string) ($tenant?->getKey() ?? 'none');
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        $keys = [];
        if ($this->platform_role_id) {
            $keys = Role::with('permissions')->find($this->platform_role_id)?->permissionKeys() ?? [];
        }

        if ($tenant) {
            $membership = $this->activeTenants()->whereKey($tenant->getKey())->first();
            if ($membership) {
                $role = Role::with('permissions')->find($membership->pivot->role_id);
                // El rol de empresa solo otorga lo que el plan vigente de esa empresa habilita (TD-024).
                $keys = array_merge($keys, PlanModules::filter($role?->permissionKeys() ?? [], $tenant->planModules()));
            }
        }

        $keys = array_values(array_unique($keys));

        // Un token API solo puede usar los permisos (abilities) con que fue emitido.
        $token = $this->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $keys = array_values(array_filter($keys, fn (string $k) => $token->can($k)));
        }

        return $this->permissionCache[$cacheKey] = $keys;
    }

    public function hasPermission(string $permission, ?Tenant $tenant): bool
    {
        return $this->is_active && in_array($permission, $this->permissionsFor($tenant), true);
    }

    public function isPlatformAdmin(): bool
    {
        return in_array($this->platformRole?->key, ['platform_super_admin', 'tiare_admin'], true);
    }
}
