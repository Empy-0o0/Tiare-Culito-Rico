<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Despacho coordinado con un transportista. */
class Shipment extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'planned_pickup_on' => 'immutable_date',
            'planned_delivery_on' => 'immutable_date',
            'picked_up_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'estimated_cost' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'packages' => 'integer',
            'destination_region' => 'integer',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(InternalPurchaseOrder::class, 'internal_purchase_order_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(LogisticsIncident::class);
    }
}
