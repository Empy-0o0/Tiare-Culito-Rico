<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Línea de una orden interna, con cantidad recibida acumulada. */
class InternalPurchaseOrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'received_quantity' => 'decimal:4',
            'line_number' => 'integer',
        ];
    }
}
