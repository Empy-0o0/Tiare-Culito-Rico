<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Orden de compra pública consultada desde la API V1 de Mercado Público (dato oficial).
 * No confundir con las órdenes internas de TIARE.
 */
class PublicPurchaseOrder extends Model
{
    use HasPublicId;

    public const SOURCE_SYSTEM = 'mercado_publico';

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['raw_payload'];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'net_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'issued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'source_updated_at' => 'immutable_datetime',
            'last_fetched_at' => 'immutable_datetime',
            'detail_fetched_at' => 'immutable_datetime',
            'items' => 'array',
            'details' => 'array',
            'raw_payload' => 'array',
        ];
    }
}
