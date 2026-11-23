<?php

namespace App\Http\Resources;

use App\Models\PublicProcurementOpportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrato interno estable de una Compra Ágil (no expone el payload externo ni ids internos).
 *
 * @mixin PublicProcurementOpportunity
 */
class CompraAgilResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detailLoaded = $this->detail_fetched_at !== null;

        return [
            'public_id' => $this->public_id,
            'code' => $this->external_code,
            'source_type' => $this->source_type,
            'data_origin' => 'official', // dato oficial ChileCompra
            'title' => $this->title,
            'description' => $this->when($detailLoaded, $this->description),
            'status' => [
                'code' => $this->status_code,
                'label' => $this->status_label,
            ],
            'call_number' => $this->call_number,
            'dates' => [
                'published_at' => $this->published_at?->toIso8601String(),
                'closes_at' => $this->closes_at?->toIso8601String(),
                'first_call_closes_at' => $this->first_call_closes_at?->toIso8601String(),
                'second_call_closes_at' => $this->second_call_closes_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
                'source_updated_at' => $this->source_updated_at?->toIso8601String(),
            ],
            'buyer' => [
                'name' => $this->buyer_name,
                'rut' => $this->buyer_rut,
                'unit' => $this->buyer_unit,
                'region_code' => $this->region_code,
                'region_name' => $this->region_name,
            ],
            'budget' => [
                'currency' => $this->currency,
                'type' => $this->budget_type,
                'estimated' => $this->estimated_budget,
                'available' => $this->available_amount,
                'available_clp' => $this->available_amount_clp,
            ],
            'offers_count' => $this->offers_count,
            'delivery' => $this->when($detailLoaded, [
                'address' => $this->delivery_address,
                'days' => $this->delivery_days,
            ]),
            'purchase_order' => [
                // Solo es confiable si el detalle fue consultado (id_orden_compra != null).
                'known' => $detailLoaded,
                'issued' => $detailLoaded ? $this->has_purchase_order : null,
                'source_id' => $this->purchase_order_source_id,
                'code' => $this->purchase_order_code,
            ],
            'reasons' => [
                'cancellation' => $this->cancellation_reason,
                'deserted' => $this->deserted_reason,
                'selection' => $this->selection_reason,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'line_number' => $i->line_number,
                'product_code' => $i->product_code,
                'name' => $i->name,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_of_measure' => $i->unit_of_measure,
            ])->values()),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($d) => [
                'external_id' => $d->external_id,
                'name' => $d->name,
            ])->values()),
            // proveedores_cotizando[] (guía V2): solo campos comerciales públicos, desde el payload de detalle.
            'quotes' => $this->whenLoaded('detailPayload', fn () => collect($this->detailPayload?->payload['proveedores_cotizando'] ?? [])
                ->filter(fn ($q) => is_array($q))
                ->map(fn (array $q) => [
                    'supplier_rut' => is_string($q['rut_proveedor'] ?? null) ? $q['rut_proveedor'] : null,
                    'supplier_name' => is_string($q['razon_social'] ?? null) ? $q['razon_social'] : null,
                    'is_small_business' => isset($q['es_emt']) ? (bool) $q['es_emt'] : null,
                    'active' => isset($q['activo']) ? (bool) $q['activo'] : null,
                    'net_amount' => is_numeric($q['valor_neto'] ?? null) ? (string) $q['valor_neto'] : null,
                    'tax_amount' => is_numeric($q['total_impuesto'] ?? null) ? (string) $q['total_impuesto'] : null,
                    'shipping_amount' => is_numeric($q['monto_despacho'] ?? null) ? (string) $q['monto_despacho'] : null,
                    'total_amount' => is_numeric($q['monto_total'] ?? null) ? (string) $q['monto_total'] : null,
                ])->values()),
            'freshness' => [
                'last_fetched_at' => $this->last_fetched_at?->toIso8601String(),
                'detail_fetched_at' => $this->detail_fetched_at?->toIso8601String(),
            ],
        ];
    }
}
