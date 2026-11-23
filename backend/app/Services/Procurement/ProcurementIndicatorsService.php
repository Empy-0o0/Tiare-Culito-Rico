<?php

namespace App\Services\Procurement;

use App\Domain\Procurement\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\InternalPurchaseOrder;
use App\Models\Invoice;
use App\Models\PurchaseRequest;
use App\Models\SupplierQuotation;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Indicadores verificables de compras privadas (CLAUDE.md §5.4 paso 12 y §1.2 #7).
 * Cada cifra declara fórmula, tamaño de muestra (n), moneda, supuestos y período. Si no hay datos,
 * el valor es null (no se inventan cifras). Montos en CLP usando el tipo de cambio guardado en cada cotización/orden.
 */
class ProcurementIndicatorsService
{
    private const TZ = 'America/Santiago';

    /** @return array{period: array, generated_at: string, currency: string, source: string, metrics: list<array>, pipeline: array, invoices: array} */
    public function compute(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->setTimezone(self::TZ)->startOfDay()->utc();
        $end = $to->setTimezone(self::TZ)->endOfDay()->utc();

        $orders = InternalPurchaseOrder::query()
            ->with(['request', 'quotation.items', 'receipts'])
            ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->whereBetween('issued_at', [$start, $end])
            ->get();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => self::TZ],
            'generated_at' => now()->toIso8601String(),
            'currency' => 'CLP',
            'source' => 'Datos registrados en TIARE por la empresa (solicitudes, cotizaciones, órdenes, recepciones y facturas).',
            'metrics' => [
                $this->spend($orders),
                $this->savingsVsEstimate($orders),
                $this->competitiveSavings($orders),
                $this->approvalCycle($start, $end),
                $this->orderCycle($orders),
                $this->deliveryCycle($start, $end),
                $this->onTime($start, $end),
                $this->incidentRate($start, $end),
                $this->quotesPerRequest($start, $end),
            ],
            'pipeline' => PurchaseRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'invoices' => $this->invoices(),
        ];
    }

    private function metric(string $key, string $label, mixed $value, string $unit, int $n, string $formula, array $assumptions = [], array $extra = []): array
    {
        return compact('key', 'label', 'value', 'unit', 'n', 'formula', 'assumptions') + $extra;
    }

    private function clp(string $amount, ?string $rate): string
    {
        return Money::mul($amount, $rate ?: '1');
    }

    private function spend($orders): array
    {
        $total = Money::add('0', ...$orders->map(fn ($o) => $this->clp((string) $o->total, $o->exchange_rate_to_clp))->all());

        return $this->metric('spend', 'Gasto comprometido', $orders->isEmpty() ? null : Money::round($total, 0), 'CLP', $orders->count(),
            'Suma del total con IVA y despacho de las órdenes emitidas en el período (excluye anuladas).',
            ['Montos en moneda extranjera convertidos con el tipo de cambio registrado en la cotización.']);
    }

    /** Ahorro contra el precio de referencia que puso quien solicitó (comparación neta, sin despacho ni IVA). */
    private function savingsVsEstimate($orders): array
    {
        $rows = $orders->filter(fn ($o) => $o->request?->estimated_total !== null);
        $estimate = '0';
        $actual = '0';
        foreach ($rows as $o) {
            $estimate = Money::add($estimate, (string) $o->request->estimated_total);
            $actual = Money::add($actual, $this->clp((string) $o->quotation->subtotal_net, $o->quotation->exchange_rate_to_clp));
        }
        $saving = Money::sub($estimate, $actual);
        $pct = Money::cmp($estimate, '0') > 0 ? round((float) Money::div($saving, $estimate) * 100, 1) : null;

        return $this->metric('savings_vs_estimate', 'Ahorro vs precio de referencia', $rows->isEmpty() ? null : Money::round($saving, 0), 'CLP', $rows->count(),
            'Σ (estimado neto de la solicitud − neto de los ítems de la cotización adjudicada), en órdenes del período.',
            ['Solo solicitudes con precio de referencia en todos sus ítems.', 'Comparación en neto: excluye IVA y despacho.', 'El precio de referencia lo informa quien solicita; no es un precio de mercado verificado.'],
            ['percent' => $pct]);
    }

    /** Ahorro por competencia: promedio de las cotizaciones válidas menos la adjudicada. */
    private function competitiveSavings($orders): array
    {
        $saving = '0';
        $n = 0;
        foreach ($orders as $o) {
            $itemCount = $o->request->items()->count();
            $valid = SupplierQuotation::query()->where('purchase_request_id', $o->purchase_request_id)
                ->where('status', '!=', 'DISCARDED')->withCount('items')->get()
                ->filter(fn ($q) => $q->items_count >= $itemCount);
            if ($valid->count() < 2) {
                continue;
            }
            $avg = Money::div(Money::add('0', ...$valid->map(fn ($q) => (string) $q->total_clp)->all()), (string) $valid->count());
            $saving = Money::add($saving, Money::sub($avg, (string) $o->quotation->total_clp));
            $n++;
        }

        return $this->metric('competitive_savings', 'Ahorro por competencia', $n ? Money::round($saving, 0) : null, 'CLP', $n,
            'Σ (promedio del total de las cotizaciones completas recibidas − total de la cotización adjudicada).',
            ['Solo compras con al menos 2 cotizaciones completas.', 'Incluye cotizaciones vencidas que estaban completas: reflejan el mercado consultado.']);
    }

    private function approvalCycle(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = PurchaseRequest::query()->whereNotNull('submitted_at')->whereNotNull('decided_at')
            ->whereIn('status', ['APPROVED', 'ORDERED', 'CLOSED'])->whereBetween('decided_at', [$start, $end])->get()
            ->map(fn ($r) => $r->submitted_at->diffInMinutes($r->decided_at) / 1440);

        return $this->durationMetric('approval_cycle', 'Tiempo de envío a aprobación', $days, 'Mediana de días entre el envío de la solicitud y su aprobación (incluye cotización).');
    }

    private function orderCycle($orders): array
    {
        $days = $orders->filter(fn ($o) => $o->request?->decided_at !== null)->map(fn ($o) => $o->request->decided_at->diffInMinutes($o->issued_at) / 1440);

        return $this->durationMetric('order_cycle', 'Tiempo de aprobación a orden', $days, 'Mediana de días entre la aprobación y la emisión de la orden.');
    }

    private function deliveryCycle(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = $this->receivedOrders($start, $end)->map(function ($o) {
            $last = CarbonImmutable::parse($o->receipts->max('received_on'), self::TZ);

            return max(0, $o->issued_at->setTimezone(self::TZ)->startOfDay()->diffInDays($last));
        });

        return $this->durationMetric('delivery_cycle', 'Tiempo de orden a recepción completa', $days, 'Mediana de días entre la emisión de la orden y la última recepción, en órdenes recibidas por completo en el período.');
    }

    private function onTime(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->receivedOrders($start, $end)->filter(fn ($o) => $o->expected_delivery_on !== null);
        $ok = $rows->filter(fn ($o) => CarbonImmutable::parse($o->receipts->max('received_on'))->lessThanOrEqualTo($o->expected_delivery_on));

        return $this->metric('on_time', 'Entregas a tiempo', $rows->isEmpty() ? null : round($ok->count() / $rows->count() * 100, 1), '%', $rows->count(),
            'Órdenes recibidas por completo en o antes de la fecha de entrega esperada ÷ órdenes recibidas con fecha esperada.',
            ['Se usa la fecha de la última recepción.', 'Órdenes sin fecha esperada no cuentan.']);
    }

    private function incidentRate(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $receipts = GoodsReceipt::query()->whereBetween('received_on', [$start->setTimezone(self::TZ)->toDateString(), $end->setTimezone(self::TZ)->toDateString()])->get();

        return $this->metric('incident_rate', 'Recepciones con incidencia', $receipts->isEmpty() ? null : round($receipts->where('has_incident', true)->count() / $receipts->count() * 100, 1), '%', $receipts->count(),
            'Recepciones marcadas con incidencia (rechazos o notas) ÷ recepciones del período.');
    }

    private function quotesPerRequest(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $requests = PurchaseRequest::query()->whereNotNull('proposed_at')->whereBetween('proposed_at', [$start, $end])
            ->withCount(['quotations' => fn ($q) => $q->where('status', '!=', 'DISCARDED')])->get();

        return $this->metric('quotes_per_request', 'Cotizaciones por compra', $requests->isEmpty() ? null : round($requests->avg('quotations_count'), 1), 'count', $requests->count(),
            'Promedio de cotizaciones no descartadas en las compras propuestas durante el período.');
    }

    private function invoices(): array
    {
        $pending = Invoice::query()->with('order')->where('status', 'REGISTERED')->where('document_type', '!=', 'CREDIT_NOTE')->get();
        $amount = Money::add('0', ...$pending->map(fn ($i) => $this->clp((string) $i->total, $i->order?->exchange_rate_to_clp))->all());
        $mismatch = InternalPurchaseOrder::query()->whereHas('invoices')->where('status', '!=', PurchaseOrderStatus::Cancelled->value)->get()
            ->filter(fn ($o) => ! app(InvoiceService::class)->summary($o)['matches'])->count();

        return [
            'pending_count' => $pending->count(),
            'pending_amount_clp' => Money::round($amount, 0),
            'overdue_count' => $pending->filter(fn ($i) => $i->isOverdue())->count(),
            'orders_with_difference' => $mismatch,
            'note' => 'Pagos informados por la empresa o el cliente; TIARE no verifica pagos ni reemplaza la contabilidad.',
        ];
    }

    private function receivedOrders(CarbonImmutable $start, CarbonImmutable $end)
    {
        $from = $start->setTimezone(self::TZ)->toDateString();
        $to = $end->setTimezone(self::TZ)->toDateString();

        return InternalPurchaseOrder::query()->with('receipts')->where('status', PurchaseOrderStatus::Received->value)->get()
            ->filter(function ($o) use ($from, $to) {
                $last = $o->receipts->max('received_on');
                $last = $last instanceof \DateTimeInterface ? $last->format('Y-m-d') : (string) $last;

                return $last >= $from && $last <= $to;
            });
    }

    private function durationMetric(string $key, string $label, $days, string $formula): array
    {
        $values = $days->values()->sort()->values();
        $n = $values->count();
        $median = null;
        if ($n) {
            $mid = intdiv($n, 2);
            $median = round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
        }

        return $this->metric($key, $label, $median, 'days', $n, $formula, ['Días corridos; se usa la mediana para que un caso extremo no distorsione el resultado.'],
            ['average' => $n ? round($values->avg(), 1) : null]);
    }
}
