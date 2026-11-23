<?php

namespace App\Services\Procurement;

use App\Domain\Procurement\PurchaseRequestStatus as Status;
use App\Models\PurchaseRequest;
use App\Models\QuotationComparison;
use App\Models\QuotationRequest;
use App\Models\Supplier;
use App\Models\SupplierEvaluation;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cotizaciones de proveedores y comparación de costo total (CLAUDE.md §5.4, pasos 3–6).
 *
 * Totales (en la moneda de la cotización, redondeo a 2 decimales):
 *   ítems = Σ round(cantidad × precio unitario)
 *   si los precios incluyen IVA: neto ítems = ítems / (1 + tasa)
 *   neto = neto ítems; IVA = (neto + despacho) × tasa; total = neto + despacho + IVA
 *   total CLP = total × tipo de cambio (1 si la moneda es CLP).
 */
class QuotationService
{
    /** Ponderaciones por defecto del puntaje (visibles en cada comparación). */
    public const DEFAULT_WEIGHTS = ['price' => 0.6, 'delivery' => 0.25, 'supplier' => 0.15];

    /** Puntaje de proveedor sin evaluaciones: neutral (supuesto declarado). */
    public const NEUTRAL_SUPPLIER_SCORE = 0.6;

    public function __construct(private readonly AuditLogger $audit) {}

    public function requestQuote(PurchaseRequest $request, Supplier $supplier, ?string $dueOn, ?string $notes, User $user): QuotationRequest
    {
        $this->assertQuoting($request);
        if ($request->quotationRequests()->where('supplier_id', $supplier->getKey())->exists()) {
            throw ValidationException::withMessages(['supplier_public_id' => 'Ya se pidió cotización a este proveedor.']);
        }
        $rfq = QuotationRequest::create([
            'purchase_request_id' => $request->getKey(),
            'supplier_id' => $supplier->getKey(),
            'due_on' => $dueOn,
            'notes' => $notes,
            'requested_by' => $user->getKey(),
        ]);
        $this->audit->log('quotations.requested', $user->getKey(), 'purchase_request', $request->public_id, ['supplier' => $supplier->public_id]);

        return $rfq;
    }

    /** @param array<string, mixed> $data */
    public function register(PurchaseRequest $request, Supplier $supplier, array $data, User $user): SupplierQuotation
    {
        $this->assertQuoting($request);
        $currency = strtoupper($data['currency'] ?? 'CLP');
        $rate = $currency === 'CLP' ? '1' : (string) ($data['exchange_rate_to_clp'] ?? '');
        if ($currency !== 'CLP' && (Money::cmp($rate, '0') <= 0)) {
            throw ValidationException::withMessages(['exchange_rate_to_clp' => 'Indica el tipo de cambio a CLP y su fuente para comparar cotizaciones en otra moneda.']);
        }

        $requestItems = $request->items()->get()->keyBy('id');
        $byLine = $request->items()->get()->keyBy('line_number');

        return DB::transaction(function () use ($request, $supplier, $data, $user, $currency, $rate, $requestItems, $byLine) {
            $rfq = $request->quotationRequests()->where('supplier_id', $supplier->getKey())->first();
            $lines = [];
            $itemsSum = '0';
            foreach ($data['items'] as $line) {
                $ri = $byLine[(int) $line['line_number']] ?? null;
                if (! $ri) {
                    throw ValidationException::withMessages(['items' => "La línea {$line['line_number']} no existe en la solicitud."]);
                }
                $qty = (string) ($line['quantity'] ?? $ri->quantity);
                $lineTotal = Money::round(Money::mul($qty, (string) $line['unit_price']));
                $itemsSum = Money::add($itemsSum, $lineTotal);
                $lines[] = ['purchase_request_item_id' => $ri->getKey(), 'quantity' => $qty, 'unit_price' => $line['unit_price'], 'line_total' => $lineTotal,
                    'offered_brand' => $line['offered_brand'] ?? null, 'notes' => $line['notes'] ?? null];
            }

            $taxRate = Money::div((string) ($data['tax_rate'] ?? '19'), '100');
            $shipping = (string) ($data['shipping_cost'] ?? '0');
            $includeTax = (bool) ($data['prices_include_tax'] ?? false);
            $net = $includeTax ? Money::round(Money::div($itemsSum, Money::add('1', $taxRate))) : Money::round($itemsSum);
            $tax = Money::round(Money::mul(Money::add($net, $shipping), $taxRate));
            $total = Money::round(Money::add($net, $shipping, $tax));

            $quotation = SupplierQuotation::create([
                'purchase_request_id' => $request->getKey(),
                'quotation_request_id' => $rfq?->getKey(),
                'supplier_id' => $supplier->getKey(),
                'reference' => $data['reference'] ?? null,
                'currency' => $currency,
                'exchange_rate_to_clp' => $currency === 'CLP' ? null : $rate,
                'exchange_rate_source' => $currency === 'CLP' ? null : ($data['exchange_rate_source'] ?? null),
                'tax_rate' => $data['tax_rate'] ?? 19,
                'prices_include_tax' => $includeTax,
                'shipping_cost' => $shipping,
                'delivery_days' => $data['delivery_days'] ?? null,
                'warranty_months' => $data['warranty_months'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'subtotal_net' => $net,
                'tax_amount' => $tax,
                'total' => $total,
                'total_clp' => Money::round(Money::mul($total, $rate)),
                'registered_by' => $user->getKey(),
                'received_on' => $data['received_on'] ?? CarbonImmutable::now('America/Santiago')->toDateString(),
            ]);
            $quotation->items()->createMany($lines);
            $rfq?->update(['status' => 'RECEIVED']);
            $this->audit->log('quotations.registered', $user->getKey(), 'purchase_request', $request->public_id, ['quotation' => $quotation->public_id, 'total_clp' => $quotation->total_clp]);

            return $quotation;
        });
    }

    public function discard(SupplierQuotation $quotation, User $user): void
    {
        if ($quotation->status === 'SELECTED') {
            throw ValidationException::withMessages(['quotation' => 'No se descarta la cotización propuesta. Rechaza la propuesta primero.']);
        }
        $quotation->update(['status' => 'DISCARDED']);
        $this->audit->log('quotations.discarded', $user->getKey(), 'supplier_quotation', $quotation->public_id);
    }

    /**
     * Elimina una cotización registrada por error (TD-031). Solo mientras la solicitud está en cotización
     * y si no es la propuesta. Las comparaciones ya generadas no cambian (son instantáneas inmutables).
     * Si era la única respuesta a una solicitud de cotización, esa solicitud vuelve a "pedida".
     */
    public function delete(SupplierQuotation $quotation, User $user): void
    {
        $request = $quotation->request;
        if ($request->status !== Status::Quoting) {
            throw ValidationException::withMessages(['quotation' => 'Solo se eliminan cotizaciones mientras la solicitud está en cotización. Después quedan como respaldo de la decisión.']);
        }
        if ($quotation->status === 'SELECTED') {
            throw ValidationException::withMessages(['quotation' => 'No se elimina la cotización propuesta.']);
        }

        DB::transaction(function () use ($quotation, $user, $request) {
            $snapshot = ['supplier' => $quotation->supplier?->public_id, 'reference' => $quotation->reference, 'total' => (string) $quotation->total, 'currency' => $quotation->currency, 'total_clp' => (string) $quotation->total_clp];
            $rfqId = $quotation->quotation_request_id;
            $quotation->items()->delete();
            $quotation->delete();
            if ($rfqId && ! SupplierQuotation::query()->where('quotation_request_id', $rfqId)->exists()) {
                QuotationRequest::query()->whereKey($rfqId)->update(['status' => 'REQUESTED']);
            }
            $this->audit->log('quotations.deleted', $user->getKey(), 'purchase_request', $request->public_id, ['quotation' => $quotation->public_id] + $snapshot);
        });
    }

    /**
     * Compara las cotizaciones de la solicitud y guarda una instantánea inmutable con metodología,
     * supuestos, valores de entrada y recomendación. Solo son elegibles las cotizaciones vigentes,
     * no descartadas y que cubren todos los ítems.
     *
     * Puntaje = w_precio × (menor total / total) + w_plazo × (menor plazo / plazo) + w_proveedor × (promedio evaluaciones / 5).
     *
     * @param  array{price?: float, delivery?: float, supplier?: float}|null  $weights
     */
    public function compare(PurchaseRequest $request, ?array $weights, User $user): QuotationComparison
    {
        $weights = $this->normalizeWeights($weights);
        $today = CarbonImmutable::now('America/Santiago')->startOfDay();
        $itemCount = $request->items()->count();
        $quotations = $request->quotations()->with(['supplier.organization', 'items'])->where('status', '!=', 'DISCARDED')->get();

        $rows = $quotations->map(function (SupplierQuotation $q) use ($today, $itemCount) {
            $expired = $q->valid_until !== null && $q->valid_until->lessThan($today);
            $covered = $q->items->count();
            $avg = SupplierEvaluation::query()->where('supplier_id', $q->supplier_id)->avg('overall_score');
            $reasons = [];
            if ($expired) {
                $reasons[] = 'Cotización vencida';
            }
            if ($covered < $itemCount) {
                $reasons[] = "Cubre {$covered} de {$itemCount} ítems";
            }

            return [
                'quotation_public_id' => $q->public_id,
                'supplier' => $q->supplier->organization->legal_name,
                'currency' => $q->currency,
                'exchange_rate_to_clp' => $q->exchange_rate_to_clp,
                'total' => (string) $q->total,
                'total_clp' => (string) $q->total_clp,
                'delivery_days' => $q->delivery_days,
                'warranty_months' => $q->warranty_months,
                'valid_until' => $q->valid_until?->toDateString(),
                'supplier_rating' => $avg !== null ? number_format((float) $avg, 2, '.', '') : null,
                'eligible' => $reasons === [],
                'excluded_reasons' => $reasons,
            ];
        })->values()->all();

        $eligible = array_values(array_filter($rows, fn ($r) => $r['eligible']));
        $minTotal = $eligible ? min(array_map(fn ($r) => (float) $r['total_clp'], $eligible)) : null;
        $days = array_filter(array_map(fn ($r) => $r['delivery_days'], $eligible), fn ($d) => $d !== null);
        $minDays = $days ? max(1, min($days)) : null;

        foreach ($rows as &$r) {
            if (! $r['eligible']) {
                $r['scores'] = null;

                continue;
            }
            $price = (float) $r['total_clp'] > 0 ? $minTotal / (float) $r['total_clp'] : 0.0;
            $delivery = $r['delivery_days'] === null ? 0.5 : $minDays / max(1, $r['delivery_days']);
            $supplier = $r['supplier_rating'] !== null ? ((float) $r['supplier_rating']) / 5 : self::NEUTRAL_SUPPLIER_SCORE;
            $r['scores'] = [
                'price' => round($price, 4),
                'delivery' => round($delivery, 4),
                'supplier' => round($supplier, 4),
                'total' => round($weights['price'] * $price + $weights['delivery'] * $delivery + $weights['supplier'] * $supplier, 4),
            ];
        }
        unset($r);

        usort($rows, fn ($a, $b) => ($b['scores']['total'] ?? -1) <=> ($a['scores']['total'] ?? -1));
        $best = ($rows[0]['scores'] ?? null) !== null ? $rows[0] : null;
        $recommendedId = $best ? SupplierQuotation::query()->where('public_id', $best['quotation_public_id'])->value('id') : null;

        $comparison = QuotationComparison::create([
            'purchase_request_id' => $request->getKey(),
            'generated_by' => $user->getKey(),
            'methodology' => [
                'version' => 1,
                'weights' => $weights,
                'formula' => 'Puntaje = precio × (menor total CLP / total CLP) + plazo × (menor plazo / plazo) + proveedor × (promedio de evaluaciones / 5).',
                'assumptions' => [
                    'Los totales incluyen IVA y despacho, convertidos a CLP con el tipo de cambio informado en cada cotización.',
                    'Sin plazo informado, el criterio de plazo vale 0,5.',
                    'Proveedor sin evaluaciones: criterio proveedor = '.self::NEUTRAL_SUPPLIER_SCORE.'.',
                    'Se excluyen cotizaciones vencidas, descartadas o que no cubren todos los ítems.',
                    'Garantía y condiciones de pago se muestran, pero no se puntúan.',
                ],
                'generated_at' => now()->toIso8601String(),
                'request_version' => $request->version,
            ],
            'results' => $rows,
            'recommended_quotation_id' => $recommendedId,
        ]);
        $this->audit->log('quotations.compared', $user->getKey(), 'purchase_request', $request->public_id, ['comparison' => $comparison->public_id]);

        return $comparison;
    }

    /** @return array{price: float, delivery: float, supplier: float} */
    private function normalizeWeights(?array $weights): array
    {
        $w = array_merge(self::DEFAULT_WEIGHTS, array_intersect_key($weights ?? [], self::DEFAULT_WEIGHTS));
        $sum = array_sum($w);
        if ($sum <= 0) {
            return self::DEFAULT_WEIGHTS;
        }

        return array_map(fn ($v) => round($v / $sum, 4), $w);
    }

    private function assertQuoting(PurchaseRequest $request): void
    {
        if ($request->status !== Status::Quoting) {
            throw ValidationException::withMessages(['status' => 'La solicitud debe estar en cotización.']);
        }
    }
}
