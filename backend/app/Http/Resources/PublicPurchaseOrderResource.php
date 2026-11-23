<?php

namespace App\Http\Resources;

use App\Models\PublicPurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato interno de una orden de compra pública (API V1).
 *
 * @mixin PublicPurchaseOrder
 */
class PublicPurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detailLoaded = $this->detail_fetched_at !== null;

        return [
            'public_id' => $this->public_id,
            'code' => $this->external_code,
            'data_origin' => 'official',
            'detail_loaded' => $detailLoaded,
            'title' => $this->title,
            'description' => $this->when($detailLoaded, $this->description),
            'status' => ['code' => $this->status_code, 'label' => $this->status_label],
            'supplier_status' => $this->supplier_status_label,
            'tender_code' => $this->tender_code,
            'type' => ['code' => $this->type_code, 'label' => $this->type_label],
            'currency' => $this->currency,
            'amounts' => [
                'net' => $this->net_total,
                'tax' => $this->tax_total,
                'tax_rate' => $this->tax_rate,
                'total' => $this->total,
            ],
            'dates' => [
                'issued_at' => $this->issued_at?->toIso8601String(),
                'sent_at' => $this->sent_at?->toIso8601String(),
                'accepted_at' => $this->accepted_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                'source_updated_at' => $this->source_updated_at?->toIso8601String(),
            ],
            'buyer' => [
                'code' => $this->buyer_code,
                'name' => $this->buyer_name,
                'unit_rut' => $this->buyer_unit_rut,
                'unit' => $this->buyer_unit,
                'region' => $this->buyer_region,
            ],
            'supplier' => [
                'code' => $this->supplier_code,
                'name' => $this->supplier_name,
                'rut' => $this->supplier_rut,
                'region' => $this->supplier_region,
            ],
            'details' => $this->when($detailLoaded, $this->details ?? []),
            'items' => $this->when($detailLoaded, $this->items ?? []),
            'freshness' => [
                'last_fetched_at' => $this->last_fetched_at?->toIso8601String(),
                'detail_fetched_at' => $this->detail_fetched_at?->toIso8601String(),
            ],
        ];
    }
}
