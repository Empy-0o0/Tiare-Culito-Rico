<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Aislamiento por tenant (CLAUDE.md §5.1).
 *
 * - Global scope: toda consulta se filtra por el tenant activo resuelto en backend.
 *   Sin tenant activo la consulta no devuelve filas (falla cerrada).
 * - Al crear, tenant_id se asigna desde el tenant activo; nunca desde la entrada del usuario.
 * - Procesos sin request (seeders) deben usar withoutTenantScope() y fijar tenant_id explícitamente.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(CurrentTenant::class)->id();
            $column = $builder->getModel()->qualifyColumn('tenant_id');
            $tenantId === null ? $builder->whereRaw('1 = 0') : $builder->where($column, $tenantId);
        });

        static::creating(function ($model): void {
            $current = app(CurrentTenant::class)->id();
            if ($model->tenant_id === null) {
                if ($current === null) {
                    throw new LogicException('No hay tenant activo para crear '.class_basename($model).'.');
                }
                $model->tenant_id = $current;
            } elseif ($current !== null && (int) $model->tenant_id !== $current) {
                throw new LogicException('tenant_id no coincide con el tenant activo.');
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('No se puede cambiar el tenant de un registro.');
            }
        });
    }

    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
