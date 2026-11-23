<?php

namespace App\Services\Procurement;

use App\Domain\Procurement\PurchaseOrderStatus as OrderStatus;
use App\Domain\Procurement\PurchaseRequestStatus as Status;
use App\Models\GoodsReceipt;
use App\Models\InternalPurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orden de compra interna y recepción (CLAUDE.md §5.4, pasos 8–10).
 * La orden copia precios y condiciones de la cotización aprobada (no se recalculan ni editan).
 * TIARE no financia la compra: la orden deja explícitos pagador, titular de la factura,
 * comprador contractual y responsable de recepción.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
        private readonly CurrentTenant $tenant,
    ) {}

    /** @param array<string, mixed> $data */
    public function issue(PurchaseRequest $request, array $data, User $user): InternalPurchaseOrder
    {
        if ($request->status !== Status::Approved) {
            throw ValidationException::withMessages(['status' => 'Solo se emite una orden para una solicitud aprobada.']);
        }
        if ($request->order()->exists()) {
            throw ValidationException::withMessages(['status' => 'La solicitud ya tiene una orden emitida.']);
        }

        return DB::transaction(function () use ($request, $data, $user) {
            $q = $request->selectedQuotation()->with('items.requestItem')->firstOrFail();
            $order = InternalPurchaseOrder::create([
                'number' => $this->numbers->next($this->tenant->id(), DocumentNumberService::ORDER),
                'purchase_request_id' => $request->getKey(),
                'supplier_quotation_id' => $q->getKey(),
                'supplier_id' => $q->supplier_id,
                'status' => OrderStatus::Issued,
                'currency' => $q->currency,
                'exchange_rate_to_clp' => $q->exchange_rate_to_clp,
                'subtotal_net' => $q->subtotal_net,
                'shipping_cost' => $q->shipping_cost,
                'tax_amount' => $q->tax_amount,
                'total' => $q->total,
                'payer_name' => $data['payer_name'],
                'invoice_holder_name' => $data['invoice_holder_name'],
                'invoice_holder_rut' => $data['invoice_holder_rut'] ?? null,
                'contractual_buyer' => $data['contractual_buyer'],
                'receiver_name' => $data['receiver_name'],
                'delivery_address' => $data['delivery_address'] ?? $request->delivery_address,
                'expected_delivery_on' => $data['expected_delivery_on'] ?? null,
                'payment_terms' => $q->payment_terms,
                'notes' => $data['notes'] ?? null,
                'issued_by' => $user->getKey(),
                'issued_at' => now(),
            ]);
            foreach ($q->items->sortBy(fn ($i) => $i->requestItem->line_number)->values() as $n => $item) {
                $order->items()->create([
                    'purchase_request_item_id' => $item->purchase_request_item_id,
                    'line_number' => $n + 1,
                    'description' => $item->requestItem->description.($item->offered_brand ? " ({$item->offered_brand})" : ''),
                    'unit_code' => $item->requestItem->unit_code,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ]);
            }
            $request->forceFill(['status' => Status::Ordered])->save();
            $this->audit->log('purchase_orders.issued', $user->getKey(), 'internal_purchase_order', $order->public_id, ['request' => $request->public_id, 'total' => $order->total, 'currency' => $order->currency]);

            return $order;
        });
    }

    public function markSent(InternalPurchaseOrder $order, User $user): InternalPurchaseOrder
    {
        if ($order->status !== OrderStatus::Issued) {
            throw ValidationException::withMessages(['status' => 'Solo se marca como enviada una orden emitida.']);
        }
        $order->forceFill(['status' => OrderStatus::Sent, 'sent_at' => now()])->save();
        $this->audit->log('purchase_orders.sent', $user->getKey(), 'internal_purchase_order', $order->public_id);

        return $order;
    }

    /**
     * Solo antes de recibir. Anular la orden anula también la solicitud (queda el registro y el motivo);
     * para volver a comprar se crea una solicitud nueva. Así una solicitud nunca tiene dos órdenes.
     */
    public function cancel(InternalPurchaseOrder $order, string $reason, User $user): InternalPurchaseOrder
    {
        if (! in_array($order->status, [OrderStatus::Issued, OrderStatus::Sent], true)) {
            throw ValidationException::withMessages(['status' => 'No se anula una orden con recepciones registradas.']);
        }

        return DB::transaction(function () use ($order, $reason, $user) {
            $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $request = $order->request;
            $request->forceFill(['status' => Status::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => 'Orden '.$order->number.' anulada: '.$reason])->save();
            $this->audit->log('purchase_orders.cancelled', $user->getKey(), 'internal_purchase_order', $order->public_id, ['reason' => $reason]);

            return $order;
        });
    }

    /**
     * Elimina una orden (TD-031) solo si no tiene recepciones ni facturas: con ellas hay mercadería
     * o dinero de por medio y corresponde anular. Si la orden estaba vigente, la solicitud vuelve a
     * "Aprobada" (la aprobación sigue siendo válida) para emitir una orden nueva. Si estaba anulada,
     * la solicitud sigue anulada. Los despachos vinculados quedan sin orden. La auditoría guarda
     * una copia de los datos principales; el número de orden no se reutiliza.
     */
    public function delete(InternalPurchaseOrder $order, User $user): void
    {
        if ($order->receipts()->exists()) {
            throw ValidationException::withMessages(['order' => 'La orden tiene recepciones registradas: no se puede eliminar.']);
        }
        if ($order->invoices()->exists()) {
            throw ValidationException::withMessages(['order' => 'La orden tiene facturas registradas: no se puede eliminar. Si no se comprará, anúlala.']);
        }

        DB::transaction(function () use ($order, $user) {
            $request = $order->request;
            $snapshot = [
                'number' => $order->number,
                'status' => $order->status->value,
                'supplier' => $order->supplier?->public_id,
                'total' => (string) $order->total,
                'currency' => $order->currency,
                'request' => $request?->public_id,
            ];
            if ($request && $request->status === Status::Ordered) {
                $request->forceFill(['status' => Status::Approved])->save();
            }
            $order->items()->delete();
            $order->delete();
            $this->audit->log('purchase_orders.deleted', $user->getKey(), 'internal_purchase_order', $order->public_id, $snapshot);
        });
    }

    /**
     * Registra una recepción (parcial o total). Cantidad recibida ≤ pendiente por línea.
     * Rechazos o notas marcan la recepción con incidencia. Al completar todo, la orden queda
     * "Recibida" y la solicitud "Recibida" (cerrada).
     *
     * @param  array{received_on: string, document_reference?: ?string, notes?: ?string, items: list<array{line_number:int, quantity_received:string|float, quantity_rejected?:string|float, incident_note?:?string}>}  $data
     */
    public function receive(InternalPurchaseOrder $order, array $data, User $user): GoodsReceipt
    {
        if (! $order->status->canReceive()) {
            throw ValidationException::withMessages(['status' => 'La orden no admite recepciones (anulada o ya recibida).']);
        }

        return DB::transaction(function () use ($order, $data, $user) {
            $lines = $order->items()->lockForUpdate()->get()->keyBy('line_number');
            $incident = (bool) ($data['has_incident'] ?? false);
            $receiptLines = [];
            foreach ($data['items'] as $row) {
                $line = $lines[(int) $row['line_number']] ?? null;
                if (! $line) {
                    throw ValidationException::withMessages(['items' => "La línea {$row['line_number']} no existe en la orden."]);
                }
                $received = (string) $row['quantity_received'];
                $rejected = (string) ($row['quantity_rejected'] ?? '0');
                $pending = Money::sub((string) $line->quantity, (string) $line->received_quantity);
                if (Money::cmp($received, $pending) > 0) {
                    throw ValidationException::withMessages(['items' => "Línea {$line->line_number}: se reciben {$received} pero quedan pendientes ".rtrim(rtrim($pending, '0'), '.').'.']);
                }
                if (Money::cmp($rejected, '0') > 0 || ! empty($row['incident_note'])) {
                    $incident = true;
                }
                if (Money::cmp($received, '0') === 0 && Money::cmp($rejected, '0') === 0) {
                    continue;
                }
                $receiptLines[] = ['internal_purchase_order_item_id' => $line->getKey(), 'quantity_received' => $received, 'quantity_rejected' => $rejected, 'incident_note' => $row['incident_note'] ?? null];
                $line->update(['received_quantity' => Money::add((string) $line->received_quantity, $received)]);
            }
            if ($receiptLines === []) {
                throw ValidationException::withMessages(['items' => 'Indica al menos una cantidad recibida o rechazada.']);
            }
            $receipt = GoodsReceipt::create([
                'internal_purchase_order_id' => $order->getKey(),
                'received_by' => $user->getKey(),
                'received_on' => $data['received_on'],
                'document_reference' => $data['document_reference'] ?? null,
                'has_incident' => $incident,
                'notes' => $data['notes'] ?? null,
            ]);
            $receipt->items()->createMany($receiptLines);

            $complete = $order->items()->get()->every(fn ($l) => Money::cmp((string) $l->received_quantity, (string) $l->quantity) >= 0);
            $order->forceFill(['status' => $complete ? OrderStatus::Received : OrderStatus::PartiallyReceived])->save();
            if ($complete) {
                $order->request->forceFill(['status' => Status::Closed])->save();
            }
            $this->audit->log('purchase_orders.received', $user->getKey(), 'internal_purchase_order', $order->public_id, ['receipt' => $receipt->public_id, 'incident' => $receipt->has_incident, 'complete' => $complete]);

            return $receipt;
        });
    }
}
