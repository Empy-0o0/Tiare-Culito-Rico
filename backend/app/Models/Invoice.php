<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Factura recibida por una orden interna (registro administrativo; no contable). */
class Invoice extends Model
{
    use BelongsToTenant, HasPublicId;

    public const TYPES = ['INVOICE' => 'Factura afecta', 'EXEMPT_INVOICE' => 'Factura exenta', 'CREDIT_NOTE' => 'Nota de crédito'];

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'paid_on' => 'immutable_date',
            'payment_informed_at' => 'immutable_datetime',
            'net_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(InternalPurchaseOrder::class, 'internal_purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by')->withTrashed();
    }

    /** Monto con signo: las notas de crédito restan. */
    public function signedTotal(): string
    {
        return $this->document_type === 'CREDIT_NOTE' ? '-'.$this->total : (string) $this->total;
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'PAID' && $this->due_on !== null && $this->due_on->lessThan(now('America/Santiago')->startOfDay());
    }
}
