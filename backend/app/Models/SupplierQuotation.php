<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cotización recibida de un proveedor. Totales calculados en el servidor. */
class SupplierQuotation extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'exchange_rate_to_clp' => 'decimal:6',
            'tax_rate' => 'decimal:2',
            'prices_include_tax' => 'boolean',
            'shipping_cost' => 'decimal:2',
            'subtotal_net' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'total_clp' => 'decimal:2',
            'delivery_days' => 'integer',
            'warranty_months' => 'integer',
            'valid_until' => 'immutable_date',
            'received_on' => 'immutable_date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierQuotationItem::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by')->withTrashed();
    }
}
