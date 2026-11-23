<?php

namespace App\Http\Resources;

use App\Domain\PublicProcurement\MercadoPublicoV1Catalog;
use App\Models\PublicProcurementOpportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato interno de una licitación (API V1). No expone el payload externo ni ids internos.
 *
 * @mixin PublicProcurementOpportunity
 */
class TenderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detailLoaded = $this->detail_fetched_at !== null;
        $details = $this->details ?? [];

        return [
            'public_id' => $this->public_id,
            'code' => $this->external_code,
            'source_type' => $this->source_type,
            'data_origin' => 'official',
            'detail_loaded' => $detailLoaded,
            'title' => $this->title,
            'description' => $this->when($detailLoaded, $this->description),
            'status' => ['code' => $this->status_code !== null ? (int) $this->status_code : null, 'label' => $this->status_label],
            'type' => [
                'code' => $this->procedure_type,
                'label' => $this->procedure_type ? (MercadoPublicoV1Catalog::TENDER_TYPES[$this->procedure_type] ?? null) : null,
            ],
            'dates' => [
                'published_at' => $this->published_at?->toIso8601String(),
                'closes_at' => $this->closes_at?->toIso8601String(),
                'awarded_at' => $this->awarded_at?->toIso8601String(),
            ],
            'buyer' => [
                'code' => $this->buyer_code,
                'name' => $this->buyer_name,
                'rut' => $this->buyer_rut,
                'unit' => $this->buyer_unit,
                'region_code' => $this->region_code,
                'region_name' => $this->region_name,
            ],
            'budget' => [
                'currency' => $this->currency,
                'estimation' => $this->budget_type,
                'estimated' => $this->estimated_budget,
            ],
            'delivery_address' => $this->when($detailLoaded, $this->delivery_address),
            'details' => $this->when($detailLoaded, $details),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'line_number' => $i->line_number,
                'product_code' => $i->product_code,
                'category' => $i->category,
                'name' => $i->name,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_of_measure' => $i->unit_of_measure,
                'award' => $i->awarded_supplier_rut || $i->awarded_supplier_name ? [
                    'supplier_rut' => $i->awarded_supplier_rut,
                    'supplier_name' => $i->awarded_supplier_name,
                    'quantity' => $i->awarded_quantity,
                    'unit_price' => $i->awarded_unit_price,
                ] : null,
            ])->values()),
            'freshness' => [
                'last_fetched_at' => $this->last_fetched_at?->toIso8601String(),
                'detail_fetched_at' => $this->detail_fetched_at?->toIso8601String(),
            ],
        ];
    }
}
