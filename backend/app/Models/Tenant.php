<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasPublicId;

    protected $fillable = ['name', 'legal_name', 'rut', 'status', 'is_demo', 'settings'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'settings' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['role_id', 'is_active', 'approval_limit_clp'])->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    /** Suscripción vigente hoy (la más reciente si hubiera varias). Se memoriza por instancia. */
    public function currentSubscription(): ?TenantSubscription
    {
        if (! $this->relationLoaded('currentSubscriptionCache')) {
            $this->setRelation('currentSubscriptionCache', $this->subscriptions()->current()->with('plan')->latest('starts_on')->latest('id')->first());
        }

        return $this->getRelation('currentSubscriptionCache');
    }

    public function forgetSubscriptionCache(): void
    {
        $this->unsetRelation('currentSubscriptionCache');
    }

    /** @return list<string> módulos habilitados por el plan vigente (vacío si no hay plan vigente). */
    public function planModules(): array
    {
        return array_values($this->currentSubscription()?->plan?->modules ?? []);
    }

    /** Personas del equipo con acceso activo (los accesos de portal de clientes/proveedores no ocupan cupo del plan). */
    public function activeMembersCount(): int
    {
        return $this->users()->wherePivot('is_active', true)->wherePivotNotIn('role_id', Role::portalRoleIds())->where('users.is_active', true)->count();
    }
}
