<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Evaluación de desempeño de un proveedor (escala 1 a 5). Inmutable: una corrección es una nueva evaluación.
 */
class SupplierEvaluation extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    public const CRITERIA = [
        'price_score' => 'Precio',
        'quality_score' => 'Calidad',
        'delivery_score' => 'Plazo de entrega',
        'compliance_score' => 'Cumplimiento de condiciones',
        'service_score' => 'Atención y gestión de reclamos',
    ];

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'evaluated_on' => 'immutable_date',
            'overall_score' => 'decimal:2',
            'had_incident' => 'boolean',
            'price_score' => 'integer',
            'quality_score' => 'integer',
            'delivery_score' => 'integer',
            'compliance_score' => 'integer',
            'service_score' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Las evaluaciones de proveedores no se modifican; registra una nueva.'));
        static::deleting(fn () => throw new LogicException('Las evaluaciones de proveedores no se eliminan.'));
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by')->withTrashed();
    }
}
