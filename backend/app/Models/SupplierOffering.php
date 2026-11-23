<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Producto (insumo) o servicio que entrega un proveedor, con su capacidad declarada (TD-026). */
class SupplierOffering extends Model
{
    use BelongsToTenant, HasPublicId;

    public const KINDS = ['PRODUCT' => 'Insumo / producto', 'SERVICE' => 'Servicio'];

    public const PERIODS = ['DAY' => 'por día', 'WEEK' => 'por semana', 'MONTH' => 'por mes', 'YEAR' => 'por año'];

    protected $fillable = [
        'supplier_id', 'kind', 'name', 'description', 'category_id', 'product_id', 'unit_of_measure_id',
        'capacity_quantity', 'capacity_period', 'min_order_quantity', 'lead_time_days', 'capacity_notes',
        'capacity_confirmed_on', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity_quantity' => 'decimal:3',
            'min_order_quantity' => 'decimal:3',
            'lead_time_days' => 'integer',
            'capacity_confirmed_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }
}
