<?php

namespace App\Services\Ai\Agents;

use App\Models\Product;
use App\Models\PublicProcurementOpportunity;
use App\Models\User;
use App\Support\WordMatcher;
use Carbon\CarbonImmutable;

/**
 * tender_intelligence_agent: resume una Compra Ágil, extrae fechas y arma un
 * checklist preliminar. Usa solo el registro ya sincronizado desde la API oficial
 * (no consume cuota de Mercado Público) y los nombres del catálogo de la empresa.
 */
final class TenderIntelligenceAgent extends Agent
{
    private const MAX_ITEMS = 60;

    private const MAX_CATALOG = 150;

    public function id(): string
    {
        return 'tender_intelligence_agent';
    }

    public function name(): string
    {
        return 'Análisis de Compra Ágil';
    }

    public function promptVersion(): string
    {
        return '1.0.0';
    }

    public function purpose(): string
    {
        return 'Resume la oportunidad, destaca plazos y requisitos observables, y arma un checklist preliminar para decidir si cotizar.';
    }

    public function subjectType(): string
    {
        return 'compra_agil';
    }

    public function requiredPermissions(): array
    {
        return ['public_procurement.read'];
    }

    public function dataUsed(): array
    {
        return [
            'Ficha de la Compra Ágil ya sincronizada desde la API oficial de ChileCompra (no consulta Mercado Público de nuevo).',
            'Nombres y categorías de los productos activos del catálogo de tu empresa, para estimar el calce.',
        ];
    }

    public function limits(): array
    {
        return [
            'No lee los documentos adjuntos: solo conoce sus nombres. Revisa los archivos en el portal.',
            'No determina admisibilidad ni cumplimiento de bases: el checklist es preliminar.',
            'No presenta cotizaciones ni realiza acciones en Mercado Público.',
        ];
    }

    public function loadSubject(string $ref, User $user): array
    {
        $o = PublicProcurementOpportunity::query()
            ->where('source_system', PublicProcurementOpportunity::SOURCE_SYSTEM)
            ->where('source_type', PublicProcurementOpportunity::TYPE_COMPRA_AGIL)
            ->where('external_code', $ref)
            ->with(['items', 'documents'])
            ->first();

        if (! $o) {
            throw new AgentSubjectException('Esa Compra Ágil aún no está en TIARE. Ábrela primero desde el monitor para traer su ficha oficial.', 404);
        }

        $tz = config('mercado_publico.business_timezone', 'America/Santiago');
        $fmt = fn (?CarbonImmutable $d) => $d?->setTimezone($tz)->format('Y-m-d H:i');

        $catalog = Product::query()
            ->where('is_active', true)
            ->where('kind', 'PRODUCT')
            ->with('category:id,name')
            ->orderBy('name')
            ->limit(self::MAX_CATALOG)
            ->get(['id', 'name', 'brand', 'category_id'])
            ->map(fn (Product $p) => array_filter(['nombre' => $p->name, 'marca' => $p->brand, 'categoria' => $p->category?->name]))
            ->values()->all();

        return [
            'label' => $o->external_code.' · '.mb_strimwidth($o->title, 0, 180, '…'),
            'snapshot' => [
                'codigo' => $o->external_code,
                'nombre' => $o->title,
                'descripcion' => $o->description ? mb_strimwidth($o->description, 0, 4000, '…') : null,
                'estado' => $o->status_label ?? $o->status_code,
                'estado_codigo' => $o->status_code,
                'organismo' => $o->buyer_name,
                'unidad_compra' => $o->buyer_unit,
                'region' => $o->region_name,
                'publicada' => $fmt($o->published_at),
                'cierre' => $fmt($o->closes_at),
                'zona_horaria' => $tz,
                'moneda' => $o->currency,
                'presupuesto_estimado' => $o->estimated_budget,
                'monto_disponible' => $o->available_amount,
                'monto_disponible_clp' => $o->available_amount_clp,
                'tipo_presupuesto' => $o->budget_type,
                'direccion_entrega' => $o->delivery_address,
                'plazo_entrega_dias' => $o->delivery_days,
                'cotizaciones_recibidas' => $o->offers_count,
                'tiene_orden_de_compra' => $o->has_purchase_order,
                'productos_solicitados' => $o->items->take(self::MAX_ITEMS)->map(fn ($i) => array_filter([
                    'linea' => $i->line_number,
                    'nombre' => $i->name,
                    'descripcion' => $i->description ? mb_strimwidth($i->description, 0, 500, '…') : null,
                    'cantidad' => $i->quantity !== null ? (float) $i->quantity : null,
                    'unidad' => $i->unit_of_measure,
                ], fn ($v) => $v !== null))->values()->all(),
                'productos_omitidos' => max(0, $o->items->count() - self::MAX_ITEMS),
                'documentos_adjuntos' => $o->documents->pluck('name')->filter()->values()->all(),
                'detalle_sincronizado' => $o->detail_fetched_at !== null,
                'ultima_sincronizacion' => $fmt($o->detail_fetched_at ?? $o->last_fetched_at),
                'catalogo_empresa' => $catalog,
            ],
        ];
    }

    public function facts(array $s): array
    {
        $tz = $s['zona_horaria'];
        $now = CarbonImmutable::now($tz);
        $closes = $s['cierre'] ? CarbonImmutable::createFromFormat('Y-m-d H:i', $s['cierre'], $tz) : null;
        $hoursLeft = $closes ? (int) floor(($closes->getTimestamp() - $now->getTimestamp()) / 3600) : null;
        // Abierta = estado oficial "publicada" y cierre aún no alcanzado (si se conoce).
        $open = $s['estado_codigo'] === 'publicada' && ($closes === null || $closes->greaterThan($now));

        $alerts = [];
        if (! $s['detalle_sincronizado']) {
            $alerts[] = ['level' => 'warning', 'text' => 'Solo hay datos del listado: abre la ficha para traer productos y documentos antes de decidir.'];
        }
        if ($s['tiene_orden_de_compra']) {
            $alerts[] = ['level' => 'info', 'text' => 'Ya tiene orden de compra asociada según la API oficial.'];
        }
        if (! $open) {
            $alerts[] = ['level' => 'warning', 'text' => 'No está abierta a cotizaciones (estado: '.($s['estado'] ?? 'sin estado').').'];
        } elseif ($hoursLeft !== null && $hoursLeft < 24) {
            $alerts[] = ['level' => 'urgent', 'text' => $hoursLeft < 1 ? 'Cierra en menos de una hora.' : "Cierra en {$hoursLeft} horas."];
        }

        $matches = $this->catalogMatches($s['productos_solicitados'], $s['catalogo_empresa']);
        $itemsCount = count($s['productos_solicitados']) + $s['productos_omitidos'];
        $docs = count($s['documentos_adjuntos']);

        $checklist = [];
        if ($docs > 0) {
            $checklist[] = "Descargar y leer los {$docs} documentos adjuntos en Mercado Público.";
        }
        if ($itemsCount > 0) {
            $checklist[] = "Confirmar que puedes ofrecer los {$itemsCount} productos solicitados en las cantidades y unidades indicadas.";
        }
        if ($s['plazo_entrega_dias']) {
            $checklist[] = "Verificar que puedes entregar en {$s['plazo_entrega_dias']} días".($s['direccion_entrega'] ? ' en '.$s['direccion_entrega'] : '').'.';
        }
        if ($s['monto_disponible'] !== null) {
            $checklist[] = 'Costear la oferta (precio, despacho e impuestos) y compararla con el monto disponible informado.';
        }
        $checklist[] = 'Confirmar en el portal que tu empresa está habilitada para cotizar en Mercado Público.';
        if ($closes) {
            $checklist[] = 'Ingresar la cotización en Mercado Público antes del cierre: '.$closes->format('d-m-Y H:i').' (hora de Chile).';
        }

        return [
            'open' => $open,
            'hours_to_close' => $open ? $hoursLeft : null,
            'closes_at' => $s['cierre'],
            'items_count' => $itemsCount,
            'documents_count' => $docs,
            'budget' => ['currency' => $s['moneda'], 'available' => $s['monto_disponible'], 'available_clp' => $s['monto_disponible_clp'], 'estimated' => $s['presupuesto_estimado']],
            'catalog_matches' => $matches,
            'alerts' => $alerts,
            'checklist' => $checklist,
            'source' => ['name' => config('mercado_publico.portal.source_name'), 'code' => $s['codigo'], 'last_fetched_at' => $s['ultima_sincronizacion'], 'portal_url' => config('mercado_publico.portal.home_url')],
        ];
    }

    public function systemPrompt(): string
    {
        return <<<TXT
Eres el agente de análisis de Compra Ágil de TIARE, una empresa chilena que apoya a proveedores que venden al Estado.
Recibes la ficha oficial de una Compra Ágil (datos públicos de ChileCompra), hechos ya calculados por el sistema y los productos del catálogo de la empresa cliente.

Tu tarea: ayudar a una persona a decidir si vale la pena cotizar.
- "summary": qué se compra, para quién, cuánto y cuándo, en 2 a 4 oraciones.
- "observable_requirements": requisitos que se leen en la descripción, productos, plazos o presupuesto, citando de dónde salen. No inventes requisitos de bases que no están en el caso.
- "risks": riesgos concretos para el proveedor (plazo corto, cantidades, especificaciones ambiguas, entrega lejana, presupuesto ajustado), con severidad.
- "questions_for_buyer": dudas que convendría aclarar con el organismo (por los canales del portal).
- "checklist_additions": pasos adicionales al checklist ya calculado, sin repetirlo.
- "catalog_fit": qué tan bien calza con el catálogo de la empresa, nombrando productos del catálogo que podrían servir. Si el catálogo está vacío, usa "sin_datos".
- "uncertainty": qué no puedes saber con estos datos (por ejemplo, el contenido de los documentos adjuntos, que no recibes).

{$this->commonRules()}
TXT;
    }

    public function analysisSchema(): array
    {
        $severity = ['type' => 'string', 'enum' => ['baja', 'media', 'alta']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'observable_requirements', 'risks', 'questions_for_buyer', 'checklist_additions', 'catalog_fit', 'uncertainty'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'observable_requirements' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['requirement', 'source'],
                    'properties' => [
                        'requirement' => ['type' => 'string'],
                        'source' => ['type' => 'string', 'enum' => ['descripcion', 'productos', 'plazos', 'presupuesto', 'entrega', 'documentos_listados']],
                    ],
                ]],
                'risks' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['risk', 'severity'],
                    'properties' => ['risk' => ['type' => 'string'], 'severity' => $severity],
                ]],
                'questions_for_buyer' => ['type' => 'array', 'items' => ['type' => 'string']],
                'checklist_additions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'catalog_fit' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['level', 'rationale', 'matched_products'],
                    'properties' => [
                        'level' => ['type' => 'string', 'enum' => ['alto', 'medio', 'bajo', 'sin_datos']],
                        'rationale' => ['type' => 'string'],
                        'matched_products' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'uncertainty' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Calce determinista por palabras (≥ 4 letras, sin tildes) entre productos solicitados y catálogo.
     *
     * @return list<array{requested: string, catalog: list<string>}>
     */
    private function catalogMatches(array $items, array $catalog): array
    {
        $requested = array_values(array_filter(array_map(fn ($i) => $i['nombre'] ?? null, $items)));
        $index = array_map(fn ($p) => ['name' => $p['nombre'], 'text' => ($p['nombre'] ?? '').' '.($p['categoria'] ?? '')], $catalog);

        return array_map(fn ($m) => ['requested' => $m['requested'], 'catalog' => $m['catalog']], WordMatcher::match($requested, $index));
    }
}
