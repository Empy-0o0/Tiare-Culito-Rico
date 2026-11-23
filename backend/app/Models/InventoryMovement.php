<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Movimiento de inventario. Inmutable: los errores se corrigen con un ajuste. */
class InventoryMovement extends Model
{
    use BelongsToTenant, HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'expires_on' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registro inmutable: no se modifica.'));
        static::deleting(fn () => throw new LogicException('Registro inmutable: no se elimina.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
