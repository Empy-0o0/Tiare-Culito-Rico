<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Oportunidad pública normalizada (Compra Ágil / licitación). Dato oficial de ChileCompra.
 */
class PublicProcurementOpportunity extends Model
{
    use HasPublicId;

    public const SOURCE_SYSTEM = 'mercado_publico';

    public const TYPE_COMPRA_AGIL = 'compra_agil';

    public const TYPE_LICITACION = 'licitacion';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'closes_at' => 'immutable_datetime',
            'first_call_closes_at' => 'immutable_datetime',
            'second_call_closes_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'awarded_at' => 'immutable_datetime',
            'details' => 'array',
            'source_updated_at' => 'immutable_datetime',
            'last_fetched_at' => 'immutable_datetime',
            'detail_fetched_at' => 'immutable_datetime',
            'has_purchase_order' => 'boolean',
            'call_number' => 'integer',
            'region_code' => 'integer',
            'offers_count' => 'integer',
            'delivery_days' => 'integer',
            'estimated_budget' => 'decimal:2',
            'available_amount' => 'decimal:2',
            'available_amount_clp' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PublicOpportunityItem::class, 'opportunity_id')->orderBy('line_number');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PublicOpportunityDocument::class, 'opportunity_id');
    }

    public function detailPayload(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PublicProcurementRawPayload::class, 'opportunity_id')->where('kind', 'detail');
    }

    public function rawPayloads(): HasMany
    {
        return $this->hasMany(PublicProcurementRawPayload::class, 'opportunity_id');
    }
}
