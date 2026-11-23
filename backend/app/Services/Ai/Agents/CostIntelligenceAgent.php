<?php

namespace App\Services\Ai\Agents;

use App\Models\PurchaseRequest;
use App\Models\SupplierEvaluation;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * cost_intelligence_agent: explica las diferencias de costo total entre las
 * cotizaciones de una solicitud privada y señala riesgos y datos faltantes.
 * No elige ni aprueba: la propuesta y la aprobación siguen siendo humanas.
 */
final class CostIntelligenceAgent extends Agent
{
    public function id(): string
    {
        return 'cost_intelligence_agent';
    }

    public function name(): string
    {
        return 'Análisis de cotizaciones';
    }

    public function promptVersion(): string
    {
        return '1.0.0';
    }

    public function purpose(): string
    {
        return 'Explica las diferencias de costo total, plazo y condiciones entre cotizaciones, y propone preguntas y puntos de negociación.';
    }

    public function subjectType(): string
    {
        return 'purchase_request';
    }

    public function requiredPermissions(): array
    {
        return ['purchase_requests.read', 'quotations.read'];
    }

    public function dataUsed(): array
    {
        return [
            'La solicitud de compra, sus ítems y el precio de referencia que informaste.',
            'Las cotizaciones no descartadas, con sus precios por ítem, plazos, garantías y condiciones.',
            'La última comparación del sistema y el promedio de evaluaciones de cada proveedor.',
        ];
    }

    public function limits(): array
    {
        return [
            'No selecciona ni aprueba cotizaciones: la propuesta y la aprobación las hace una persona.',
            'No conoce precios de mercado fuera de las cotizaciones registradas.',
            'No contacta proveedores.',
        ];
    }

    public function loadSubject(string $ref, User $user): array
    {
        /** @var PurchaseRequest|null $r */
        $r = PurchaseRequest::query()->where('public_id', $ref)->with([
            'items',
            'quotations' => fn ($q) => $q->where('status', '!=', 'DISCARDED')->with(['supplier.organization', 'items.requestItem']),
            'comparisons' => fn ($q) => $q->latest('id')->limit(1),
        ])->first();

        if (! $r) {
            throw new AgentSubjectException('La solicitud no existe en tu empresa.', 404);
        }
        if ($r->quotations->isEmpty()) {
            throw new AgentSubjectException('La solicitud aún no tiene cotizaciones vigentes que analizar.');
        }

        $comparison = $r->comparisons->first();

        return [
            'label' => $r->number.' · '.mb_strimwidth($r->title, 0, 180, '…'),
            'snapshot' => [
                'solicitud' => [
                    'numero' => $r->number,
                    'titulo' => $r->title,
                    'descripcion' => $r->description ? mb_strimwidth($r->description, 0, 2000, '…') : null,
                    'prioridad' => $r->priority,
                    'fecha_requerida' => $r->needed_by?->toDateString(),
                    'moneda' => $r->currency,
                    'total_estimado' => $r->estimated_total !== null ? (string) $r->estimated_total : null,
                    'version' => $r->version,
                    'items' => $r->items->map(fn ($i) => [
                        'linea' => $i->line_number,
                        'descripcion' => $i->description,
                        'cantidad' => (string) $i->quantity,
                        'unidad' => $i->unit_code,
                        'precio_unitario_referencia' => $i->estimated_unit_price !== null ? (string) $i->estimated_unit_price : null,
                    ])->values()->all(),
                ],
                'cotizaciones' => $r->quotations->map(fn (SupplierQuotation $q) => [
                    'id' => $q->public_id,
                    'proveedor' => $q->supplier->organization->legal_name,
                    'evaluacion_promedio_proveedor' => ($avg = SupplierEvaluation::query()->where('supplier_id', $q->supplier_id)->avg('overall_score')) !== null ? round((float) $avg, 2) : null,
                    'moneda' => $q->currency,
                    'tipo_cambio_clp' => $q->exchange_rate_to_clp !== null ? (string) $q->exchange_rate_to_clp : null,
                    'precios_incluyen_iva' => $q->prices_include_tax,
                    'tasa_iva' => (string) $q->tax_rate,
                    'despacho_neto' => (string) $q->shipping_cost,
                    'subtotal_neto' => (string) $q->subtotal_net,
                    'iva' => (string) $q->tax_amount,
                    'total' => (string) $q->total,
                    'total_clp' => (string) $q->total_clp,
                    'plazo_entrega_dias' => $q->delivery_days,
                    'garantia_meses' => $q->warranty_months,
                    'condiciones_pago' => $q->payment_terms,
                    'vigente_hasta' => $q->valid_until?->toDateString(),
                    'estado' => $q->status,
                    'notas' => $q->notes ? mb_strimwidth($q->notes, 0, 500, '…') : null,
                    'items' => $q->items->map(fn ($i) => [
                        'linea' => $i->requestItem?->line_number,
                        'cantidad' => (string) $i->quantity,
                        'precio_unitario' => (string) $i->unit_price,
                        'marca_ofrecida' => $i->offered_brand,
                    ])->values()->all(),
                ])->values()->all(),
                'ultima_comparacion' => $comparison ? [
                    'metodologia' => $comparison->methodology,
                    'resultados' => $comparison->results,
                ] : null,
                'hoy' => CarbonImmutable::now('America/Santiago')->toDateString(),
            ],
        ];
    }

    public function facts(array $s): array
    {
        $req = $s['solicitud'];
        $today = $s['hoy'];
        $itemCount = count($req['items']);
        $rows = [];
        foreach ($s['cotizaciones'] as $q) {
            $expired = $q['vigente_hasta'] !== null && $q['vigente_hasta'] < $today;
            $complete = count($q['items']) >= $itemCount;
            $rows[] = ['id' => $q['id'], 'supplier' => $q['proveedor'], 'total_clp' => $q['total_clp'], 'delivery_days' => $q['plazo_entrega_dias'],
                'expired' => $expired, 'complete' => $complete, 'comparable' => ! $expired && $complete];
        }
        $comparable = array_values(array_filter($rows, fn ($r) => $r['comparable']));
        usort($comparable, fn ($a, $b) => Money::cmp($a['total_clp'], $b['total_clp']));
        $cheapest = $comparable[0] ?? null;
        $highest = $comparable ? $comparable[count($comparable) - 1] : null;

        $spread = null;
        if ($cheapest && $highest && Money::cmp($cheapest['total_clp'], '0') > 0 && $cheapest['id'] !== $highest['id']) {
            $spread = [
                'amount_clp' => Money::round(Money::sub($highest['total_clp'], $cheapest['total_clp'])),
                'percent' => round(((float) $highest['total_clp'] / (float) $cheapest['total_clp'] - 1) * 100, 1),
            ];
        }

        // Referencia: total estimado de la solicitud (neto, informado por el solicitante) vs menor total con IVA.
        $vsEstimate = null;
        if ($cheapest && $req['total_estimado'] !== null && $req['moneda'] === 'CLP' && (float) $req['total_estimado'] > 0) {
            $vsEstimate = round(((float) $cheapest['total_clp'] / (float) $req['total_estimado'] - 1) * 100, 1);
        }

        $alerts = [];
        foreach ($rows as $r) {
            if ($r['expired']) {
                $alerts[] = ['level' => 'warning', 'text' => "La cotización de {$r['supplier']} está vencida: pide que la renueve antes de decidir."];
            }
            if (! $r['complete']) {
                $alerts[] = ['level' => 'warning', 'text' => "La cotización de {$r['supplier']} no cubre todos los ítems."];
            }
            if ($r['delivery_days'] === null) {
                $alerts[] = ['level' => 'info', 'text' => "{$r['supplier']} no informó plazo de entrega."];
            }
        }
        if (count($comparable) < 2) {
            $alerts[] = ['level' => 'info', 'text' => 'Hay menos de dos cotizaciones comparables: la competencia de precios es limitada.'];
        }

        $comparison = $s['ultima_comparacion'];
        $recommended = $comparison['resultados'][0] ?? null;
        $comparisonInfo = null;
        if ($comparison) {
            $ids = array_column($comparison['resultados'], 'quotation_public_id');
            $current = array_column($s['cotizaciones'], 'id');
            $stale = ($comparison['metodologia']['request_version'] ?? null) !== $req['version'] || array_diff($current, $ids) !== [];
            $comparisonInfo = [
                'recommended_supplier' => ($recommended['scores'] ?? null) !== null ? $recommended['supplier'] : null,
                'recommended_is_cheapest' => $cheapest && ($recommended['quotation_public_id'] ?? null) === $cheapest['id'],
                'stale' => $stale,
            ];
            if ($stale) {
                $alerts[] = ['level' => 'warning', 'text' => 'La última comparación no considera la versión actual de la solicitud o todas sus cotizaciones: vuelve a comparar.'];
            }
        }

        return [
            'quotations' => count($rows),
            'comparable' => count($comparable),
            'cheapest' => $cheapest ? ['supplier' => $cheapest['supplier'], 'total_clp' => $cheapest['total_clp']] : null,
            'spread' => $spread,
            'cheapest_vs_estimate_percent' => $vsEstimate,
            'comparison' => $comparisonInfo,
            'alerts' => $alerts,
            'notes' => [
                'Totales en CLP con IVA y despacho, convertidos con el tipo de cambio de cada cotización.',
                'La diferencia contra el estimado compara el menor total (con IVA) con el total estimado por el solicitante; es una referencia, no un precio de mercado.',
            ],
        ];
    }

    public function systemPrompt(): string
    {
        return <<<TXT
Eres el agente de análisis de cotizaciones de TIARE, que gestiona compras privadas para empresas chilenas.
Recibes una solicitud de compra, sus cotizaciones (con precios, plazos y condiciones), la última comparación del sistema y hechos ya calculados.

Tu tarea: ayudar a la persona que propone y a quien aprueba a entender las diferencias, sin decidir por ellas.
- "summary": qué se compra y cómo se comparan las ofertas, en 2 a 4 oraciones, mencionando costo total, plazo y condiciones.
- "quotation_notes": por cada proveedor, fortalezas y debilidades concretas (precio por ítem, despacho, plazo, garantía, pago, vigencia, marcas ofrecidas, evaluación).
- "risks": riesgos de la compra (ofertas incompletas o vencidas, plazos frente a la fecha requerida, marcas distintas, diferencias de precio inusuales), con severidad.
- "questions_for_suppliers": preguntas concretas para aclarar con cada proveedor.
- "negotiation_points": dónde hay margen razonable para negociar, basado en los datos (por ejemplo, un ítem mucho más caro que en otra cotización).
- "data_gaps": datos que faltan para comparar bien.
- "uncertainty": límites de este análisis.
No digas cuál "debe" aprobarse; puedes señalar cuál destaca en cada criterio.

{$this->commonRules()}
TXT;
    }

    public function analysisSchema(): array
    {
        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'quotation_notes', 'risks', 'questions_for_suppliers', 'negotiation_points', 'data_gaps', 'uncertainty'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'quotation_notes' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['supplier', 'strengths', 'weaknesses'],
                    'properties' => ['supplier' => ['type' => 'string'], 'strengths' => $list, 'weaknesses' => $list],
                ]],
                'risks' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['risk', 'severity'],
                    'properties' => ['risk' => ['type' => 'string'], 'severity' => ['type' => 'string', 'enum' => ['baja', 'media', 'alta']]],
                ]],
                'questions_for_suppliers' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['supplier', 'question'],
                    'properties' => ['supplier' => ['type' => 'string'], 'question' => ['type' => 'string']],
                ]],
                'negotiation_points' => $list,
                'data_gaps' => $list,
                'uncertainty' => ['type' => 'string'],
            ],
        ];
    }
}
