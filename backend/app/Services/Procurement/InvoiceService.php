<?php

namespace App\Services\Procurement;

use App\Domain\Procurement\PurchaseOrderStatus;
use App\Models\InternalPurchaseOrder;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Facturas de proveedores asociadas a órdenes internas y pago informado (CLAUDE.md §5.4 paso 11).
 * TIARE no paga ni verifica pagos: registra lo que informa el cliente o la empresa, con quién y cuándo.
 * Las diferencias contra la orden se muestran (no se bloquean): pueden existir notas de crédito o ajustes.
 */
class InvoiceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function register(InternalPurchaseOrder $order, array $data, User $user): Invoice
    {
        if ($order->status === PurchaseOrderStatus::Cancelled) {
            throw ValidationException::withMessages(['order' => 'No se registran facturas de una orden anulada.']);
        }
        $currency = strtoupper($data['currency'] ?? $order->currency);
        if ($currency !== $order->currency) {
            throw ValidationException::withMessages(['currency' => "La orden está en {$order->currency}; registra la factura en la misma moneda."]);
        }

        $net = Money::round((string) $data['net_amount']);
        $tax = Money::round((string) ($data['tax_amount'] ?? '0'));

        try {
            $invoice = Invoice::create([
                'internal_purchase_order_id' => $order->getKey(),
                'supplier_id' => $order->supplier_id,
                'number' => trim($data['number']),
                'document_type' => $data['document_type'] ?? 'INVOICE',
                'issued_on' => $data['issued_on'],
                'due_on' => $data['due_on'] ?? null,
                'currency' => $currency,
                'net_amount' => $net,
                'tax_amount' => $tax,
                'total' => Money::round(Money::add($net, $tax)),
                'status' => 'REGISTERED',
                'notes' => $data['notes'] ?? null,
                'registered_by' => $user->getKey(),
            ]);
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains($e->getMessage(), '1062')) {
                throw ValidationException::withMessages(['number' => 'Ya está registrado ese folio para este proveedor.']);
            }
            throw $e;
        }
        $this->audit->log('invoices.registered', $user->getKey(), 'invoice', $invoice->public_id, ['order' => $order->public_id, 'total' => $invoice->total]);

        return $invoice;
    }

    /** @param array{paid_on: string, payment_reference?: ?string, paid_by_label: string} $data */
    public function informPayment(Invoice $invoice, array $data, User $user): Invoice
    {
        if ($invoice->status === 'PAID') {
            throw ValidationException::withMessages(['status' => 'El pago de esta factura ya fue informado.']);
        }
        if ($invoice->document_type === 'CREDIT_NOTE') {
            throw ValidationException::withMessages(['status' => 'Una nota de crédito no se paga: se descuenta de la orden.']);
        }

        return DB::transaction(function () use ($invoice, $data, $user) {
            $invoice->forceFill([
                'status' => 'PAID',
                'paid_on' => $data['paid_on'],
                'payment_reference' => $data['payment_reference'] ?? null,
                'paid_by_label' => $data['paid_by_label'],
                'payment_informed_by' => $user->getKey(),
                'payment_informed_at' => now(),
            ])->save();
            $this->audit->log('invoices.payment_informed', $user->getKey(), 'invoice', $invoice->public_id, ['paid_on' => $data['paid_on']]);

            return $invoice;
        });
    }

    /** @return array{invoiced_total: string, order_total: string, difference: string, matches: bool, paid_total: string} */
    public function summary(InternalPurchaseOrder $order): array
    {
        $invoices = $order->invoices()->get();
        $invoiced = Money::round(Money::add('0', ...$invoices->map(fn (Invoice $i) => $i->signedTotal())->all()));
        $paid = Money::round(Money::add('0', ...$invoices->where('status', 'PAID')->map(fn (Invoice $i) => (string) $i->total)->all()));
        $difference = Money::round(Money::sub($invoiced, (string) $order->total));

        return [
            'invoiced_total' => $invoiced,
            'order_total' => (string) $order->total,
            'difference' => $difference,
            'matches' => Money::cmp($difference, '0') === 0,
            'paid_total' => $paid,
        ];
    }
}
