<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cantidades recibidas y rechazadas por línea de la orden. */
class GoodsReceiptItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
            'quantity_rejected' => 'decimal:4',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(InternalPurchaseOrderItem::class, 'internal_purchase_order_item_id');
    }
}
