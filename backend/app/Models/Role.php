<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends Model
{
    /** Un rol del sistema eliminado queda marcado (TD-031) para que el seeder no lo recree. */
    use SoftDeletes;

    /** Roles que nunca se eliminan: administración de plataforma, portales y administrador de empresa. */
    public const UNDELETABLE_KEYS = ['platform_super_admin', 'tiare_admin', 'customer_portal', 'supplier_portal', 'tenant_admin'];

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_TENANT = 'tenant';

    /** Roles de portal (TD-028): externos a la empresa, se gestionan desde la ficha del cliente/proveedor. */
    public const PORTAL_KEYS = ['customer_portal', 'supplier_portal'];

    /** @return list<int> */
    public static function portalRoleIds(): array
    {
        return static::query()->whereIn('key', self::PORTAL_KEYS)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected $fillable = ['key', 'name', 'scope'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->permissions->pluck('key')->all();
    }
}
