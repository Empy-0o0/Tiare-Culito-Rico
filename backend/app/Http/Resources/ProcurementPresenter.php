<?php

namespace App\Http\Resources;

use App\Models\GoodsReceipt;
use App\Models\InternalPurchaseOrder;
use App\Models\Invoice;
use App\Services\Procurement\InvoiceService;
use App\Models\PurchaseApproval;
use App\Models\PurchaseRequest;
use App\Models\QuotationComparison;
use App\Models\QuotationRequest;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Support\CurrentTenant;

/**
 * Contrato JSON de compras privadas. No expone ids internos ni campos de auditoría técnica.
 * `actions` indica qué puede hacer el usuario actual (solo para la UI; el backend vuelve a validar).
 */
final class ProcurementPresenter
{
    public static function requestSummary(PurchaseRequest $r): array
    {
        return [
            'public_id' => $r->public_id,
            'number' => $r->number,
            'title' => $r->title,
            'status' => ['code' => $r->status->value, 'label' => $r->status->label()],
            'priority' => ['code' => $r->priority, 'label' => PurchaseRequest::PRIORITIES[$r->priority] ?? $r->priority],
            'needed_by' => $r->needed_by?->toDateString(),
            'currency' => $r->currency,
            'estimated_total' => $r->estimated_total,
            'selected_total_clp' => $r->relationLoaded('selectedQuotation') ? $r->selectedQuotation?->total_clp : null,
            'items_count' => $r->items_count ?? $r->items()->count(),
            'quotations_count' => $r->quotations_count ?? null,
            'requested_by' => $r->requester?->name,
            'customer' => $r->customer?->organization?->legal_name,
            'created_at' => $r->created_at?->toIso8601String(),
            'data_origin' => 'client',
        ];
    }

    public static function requestDetail(PurchaseRequest $r, User $user): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $can = fn (string $p) => $user->hasPermission($p, $tenant);
        $s = $r->status->value;
        $isRequester = $r->requested_by === $user->getKey();
        $comparison = $r->comparisons->first();

        return self::requestSummary($r) + [
            'description' => $r->description,
            'cost_center' => $r->cost_center,
            'delivery_address' => $r->delivery_address,
            'customer_public_id' => $r->customer?->public_id,
            'version' => $r->version,
            'proposed_by' => $r->proposer?->name,
            'proposal_justification' => $r->proposal_justification,
            'selected_quotation_public_id' => $r->selectedQuotation?->public_id,
            'dates' => [
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'proposed_at' => $r->proposed_at?->toIso8601String(),
                'decided_at' => $r->decided_at?->toIso8601String(),
                'cancelled_at' => $r->cancelled_at?->toIso8601String(),
            ],
            'cancel_reason' => $r->cancel_reason,
            'items' => $r->items->map(fn ($i) => [
                'line_number' => $i->line_number,
                'product_public_id' => $i->product?->public_id,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_code' => $i->unit_code,
                'estimated_unit_price' => $i->estimated_unit_price,
                'notes' => $i->notes,
            ])->values(),
            'quotation_requests' => $r->quotationRequests->map(fn (QuotationRequest $q) => [
                'public_id' => $q->public_id,
                'supplier' => ['public_id' => $q->supplier->public_id, 'name' => $q->supplier->organization->legal_name],
                'status' => $q->status,
                'due_on' => $q->due_on?->toDateString(),
                'notes' => $q->notes,
            ])->values(),
            'quotations' => $r->quotations->map(fn (SupplierQuotation $q) => self::quotation($q))->values(),
            'comparison' => $comparison ? self::comparison($comparison) : null,
            'approvals' => $r->approvals->map(fn (PurchaseApproval $a) => [
                'public_id' => $a->public_id,
                'decision' => $a->decision,
                'comment' => $a->comment,
                'user' => $a->user?->name,
                'request_version' => $a->request_version,
                'amount_clp' => $a->amount_clp,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
            'order' => $r->order ? self::orderSummary($r->order) : null,
            'actions' => [
                'edit' => in_array($s, ['DRAFT', 'SUBMITTED'], true) && ($isRequester || $can('purchase_requests.approve')) && $can('purchase_requests.create'),
                'submit' => $s === 'DRAFT' && $can('purchase_requests.submit'),
                'start_quoting' => $s === 'SUBMITTED' && $can('quotations.create'),
                'manage_quotations' => $s === 'QUOTING' && $can('quotations.create'),
                'delete_quotations' => $s === 'QUOTING' && $can('quotations.delete'),
                'compare' => $s === 'QUOTING' && $can('quotations.compare'),
                'propose' => $s === 'QUOTING' && $can('quotations.compare'),
                'decide' => $s === 'PENDING_APPROVAL' && $r->proposed_by !== $user->getKey() && ($can('purchase_requests.approve') || $can('purchase_requests.reject')),
                'decide_blocked_reason' => $s === 'PENDING_APPROVAL' && $r->proposed_by === $user->getKey() ? 'Propusiste esta compra: debe aprobarla otra persona.' : null,
                'issue_order' => $s === 'APPROVED' && $can('purchase_orders.create'),
                'cancel' => $r->status->isCancellable() && (($isRequester && in_array($s, ['DRAFT', 'SUBMITTED'], true)) || $can('purchase_requests.reject')),
            ],
        ];
    }

    public static function quotation(SupplierQuotation $q): array
    {
        return [
            'public_id' => $q->public_id,
            'supplier' => ['public_id' => $q->supplier->public_id, 'name' => $q->supplier->organization->legal_name],
            'reference' => $q->reference,
            'status' => $q->status,
            'currency' => $q->currency,
            'exchange_rate_to_clp' => $q->exchange_rate_to_clp,
            'exchange_rate_source' => $q->exchange_rate_source,
            'tax_rate' => $q->tax_rate,
            'prices_include_tax' => $q->prices_include_tax,
            'shipping_cost' => $q->shipping_cost,
            'subtotal_net' => $q->subtotal_net,
            'tax_amount' => $q->tax_amount,
            'total' => $q->total,
            'total_clp' => $q->total_clp,
            'delivery_days' => $q->delivery_days,
            'warranty_months' => $q->warranty_months,
            'payment_terms' => $q->payment_terms,
            'valid_until' => $q->valid_until?->toDateString(),
            'expired' => $q->valid_until !== null && $q->valid_until->lessThan(now('America/Santiago')->startOfDay()),
            'received_on' => $q->received_on?->toDateString(),
            'notes' => $q->notes,
            'items' => $q->items->map(fn ($i) => [
                'line_number' => $i->requestItem?->line_number,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
                'line_total' => $i->line_total,
                'offered_brand' => $i->offered_brand,
            ])->sortBy('line_number')->values(),
            'data_origin' => 'client',
        ];
    }

    public static function comparison(QuotationComparison $c): array
    {
        return [
            'public_id' => $c->public_id,
            'methodology' => $c->methodology,
            'results' => $c->results,
            'recommended_quotation_public_id' => $c->recommended_quotation_id ? SupplierQuotation::query()->whereKey($c->recommended_quotation_id)->value('public_id') : null,
            'generated_by' => $c->generator?->name,
            'created_at' => $c->created_at?->toIso8601String(),
            'data_origin' => 'calculated',
        ];
    }

    public static function invoice(Invoice $i): array
    {
        return [
            'public_id' => $i->public_id,
            'number' => $i->number,
            'document_type' => ['code' => $i->document_type, 'label' => Invoice::TYPES[$i->document_type] ?? $i->document_type],
            'issued_on' => $i->issued_on?->toDateString(),
            'due_on' => $i->due_on?->toDateString(),
            'currency' => $i->currency,
            'net_amount' => $i->net_amount,
            'tax_amount' => $i->tax_amount,
            'total' => $i->total,
            'status' => $i->status,
            'overdue' => $i->isOverdue(),
            'paid_on' => $i->paid_on?->toDateString(),
            'payment_reference' => $i->payment_reference,
            'paid_by_label' => $i->paid_by_label,
            'notes' => $i->notes,
            'supplier' => $i->relationLoaded('supplier') ? $i->supplier->organization->legal_name : null,
            'order' => $i->relationLoaded('order') ? ['public_id' => $i->order->public_id, 'number' => $i->order->number] : null,
            'data_origin' => 'client',
        ];
    }

    public static function orderSummary(InternalPurchaseOrder $o): array
    {
        return [
            'public_id' => $o->public_id,
            'number' => $o->number,
            'status' => ['code' => $o->status->value, 'label' => $o->status->label()],
            'supplier' => ['public_id' => $o->supplier->public_id, 'name' => $o->supplier->organization->legal_name],
            'currency' => $o->currency,
            'total' => $o->total,
            'issued_at' => $o->issued_at?->toIso8601String(),
            'expected_delivery_on' => $o->expected_delivery_on?->toDateString(),
            'request' => ['public_id' => $o->request->public_id, 'number' => $o->request->number, 'title' => $o->request->title],
        ];
    }

    public static function orderDetail(InternalPurchaseOrder $o, User $user): array
    {
        $tenant = app(CurrentTenant::class)->get();
        $s = $o->status;

        return self::orderSummary($o) + [
            'exchange_rate_to_clp' => $o->exchange_rate_to_clp,
            'subtotal_net' => $o->subtotal_net,
            'shipping_cost' => $o->shipping_cost,
            'tax_amount' => $o->tax_amount,
            'responsibles' => [
                'payer_name' => $o->payer_name,
                'invoice_holder_name' => $o->invoice_holder_name,
                'invoice_holder_rut' => $o->invoice_holder_rut,
                'contractual_buyer' => $o->contractual_buyer,
                'receiver_name' => $o->receiver_name,
            ],
            'delivery_address' => $o->delivery_address,
            'payment_terms' => $o->payment_terms,
            'notes' => $o->notes,
            'issued_by' => $o->issuer?->name,
            'sent_at' => $o->sent_at?->toIso8601String(),
            'cancelled_at' => $o->cancelled_at?->toIso8601String(),
            'cancel_reason' => $o->cancel_reason,
            'items' => $o->items()->orderBy('line_number')->get()->map(fn ($i) => [
                'line_number' => $i->line_number,
                'description' => $i->description,
                'unit_code' => $i->unit_code,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
                'line_total' => $i->line_total,
                'received_quantity' => $i->received_quantity,
            ])->values(),
            'receipts' => $o->receipts()->with(['items.orderItem', 'receiver'])->orderByDesc('id')->get()->map(fn (GoodsReceipt $g) => [
                'public_id' => $g->public_id,
                'received_on' => $g->received_on?->toDateString(),
                'document_reference' => $g->document_reference,
                'has_incident' => $g->has_incident,
                'notes' => $g->notes,
                'received_by' => $g->receiver?->name,
                'items' => $g->items->map(fn ($i) => [
                    'line_number' => $i->orderItem->line_number,
                    'quantity_received' => $i->quantity_received,
                    'quantity_rejected' => $i->quantity_rejected,
                    'incident_note' => $i->incident_note,
                ])->values(),
            ])->values(),
            'invoices' => $o->invoices()->orderByDesc('issued_on')->get()->map(fn (Invoice $i) => self::invoice($i))->values(),
            'invoicing' => app(InvoiceService::class)->summary($o),
            'actions' => [
                'register_invoice' => $s->value !== 'CANCELLED' && $user->hasPermission('invoices.manage', $tenant),
                'inform_payment' => $user->hasPermission('invoices.manage', $tenant),
                'mark_sent' => $s->value === 'ISSUED' && $user->hasPermission('purchase_orders.create', $tenant),
                'receive' => $s->canReceive() && $user->hasPermission('purchase_orders.receive', $tenant),
                'cancel' => in_array($s->value, ['ISSUED', 'SENT'], true) && $user->hasPermission('purchase_orders.cancel', $tenant),
                // Sin recepciones ni facturas (TD-031); con ellas corresponde anular.
                'delete' => ! $o->receipts()->exists() && ! $o->invoices()->exists() && $user->hasPermission('purchase_orders.delete', $tenant),
            ],
            'data_origin' => 'client',
        ];
    }
}
