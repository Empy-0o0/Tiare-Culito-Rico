<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\PublicProcurement\ChileRegion;
use App\Http\Controllers\Controller;
use App\Models\Carrier;
use App\Models\InternalPurchaseOrder;
use App\Models\LogisticsIncident;
use App\Models\Shipment;
use App\Rules\ValidChileanRut;
use App\Services\Audit\AuditLogger;
use App\Services\Logistics\ShipmentService;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Transportistas, despachos, eventos, incidencias y evidencia de entrega (CLAUDE.md §5.6). */
class LogisticsController extends Controller
{
    public function __construct(private readonly ShipmentService $shipments, private readonly CurrentTenant $tenant) {}

    public function references(): JsonResponse
    {
        $opts = fn (array $m) => collect($m)->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values();

        return ApiResponse::ok([
            'statuses' => $opts(ShipmentService::STATUSES),
            'service_types' => $opts(ShipmentService::SERVICE_TYPES),
            'incident_types' => $opts(ShipmentService::INCIDENT_TYPES),
        ]);
    }

    // ---------------------------------------------------------------- Transportistas

    public function carriers(): JsonResponse
    {
        return ApiResponse::ok(Carrier::query()->orderBy('name')->get()->map(fn ($c) => $this->carrier($c))->values());
    }

    public function storeCarrier(Request $request, AuditLogger $audit): JsonResponse
    {
        $c = Carrier::create($this->validateCarrier($request, null));
        $audit->log('logistics.carrier_created', auditableType: 'carrier', auditableId: $c->public_id);

        return ApiResponse::ok($this->carrier($c), status: 201);
    }

    public function updateCarrier(Request $request, Carrier $carrier, AuditLogger $audit): JsonResponse
    {
        $v = $this->validateCarrier($request, $carrier);
        $carrier->update($v);
        $audit->log('logistics.carrier_updated', auditableType: 'carrier', auditableId: $carrier->public_id, metadata: ['fields' => array_keys($v)]);

        return ApiResponse::ok($this->carrier($carrier));
    }

    // ---------------------------------------------------------------- Despachos

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_keys(ShipmentService::STATUSES))],
            'late' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $today = now('America/Santiago')->toDateString();
        $page = Shipment::query()->with(['carrier', 'order'])->withCount(['incidents as open_incidents_count' => fn ($q) => $q->where('status', 'OPEN')])
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('destination', 'like', $like)->orWhere('tracking_code', 'like', $like));
            })
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('late'), fn ($q) => $q->whereIn('status', ['PLANNED', 'IN_TRANSIT', 'FAILED'])->whereNotNull('planned_delivery_on')->where('planned_delivery_on', '<', $today))
            ->orderByRaw("CASE status WHEN 'FAILED' THEN 0 WHEN 'IN_TRANSIT' THEN 1 WHEN 'PLANNED' THEN 2 ELSE 3 END")
            ->orderBy('planned_delivery_on')
            ->orderByDesc('id')
            ->paginate((int) ($v['per_page'] ?? 25));

        $counts = Shipment::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ApiResponse::paginated($page, collect($page->items())->map(fn ($s) => $this->summary($s))->values(), ['status_counts' => $counts]);
    }

    public function store(Request $request): JsonResponse
    {
        $s = $this->shipments->create($this->validateShipment($request, false), $request->user());

        return ApiResponse::ok($this->detail($s, $request), status: 201);
    }

    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        return ApiResponse::ok($this->detail($shipment, $request));
    }

    public function update(Request $request, Shipment $shipment): JsonResponse
    {
        $this->shipments->update($shipment, $this->validateShipment($request, true), $request->user());

        return ApiResponse::ok($this->detail($shipment, $request));
    }

    public function status(Request $request, Shipment $shipment): JsonResponse
    {
        $v = $request->validate([
            'status' => ['required', Rule::in(['IN_TRANSIT', 'FAILED', 'RETURNED', 'CANCELLED'])],
            'description' => ['nullable', 'string', 'max:500', 'required_if:status,FAILED'],
            'location' => ['nullable', 'string', 'max:160'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ], ['description.required_if' => 'Indica por qué falló la entrega.']);
        $this->shipments->changeStatus($shipment, $v['status'], $v['description'] ?? null, $v['location'] ?? null, $v['occurred_at'] ?? null, $request->user());

        return ApiResponse::ok($this->detail($shipment, $request));
    }

    public function note(Request $request, Shipment $shipment): JsonResponse
    {
        $v = $request->validate(['description' => ['required', 'string', 'min:3', 'max:500'], 'location' => ['nullable', 'string', 'max:160'], 'occurred_at' => ['nullable', 'date', 'before_or_equal:now']]);
        $this->shipments->note($shipment, $v['description'], $v['location'] ?? null, $v['occurred_at'] ?? null, $request->user());

        return ApiResponse::ok($this->detail($shipment, $request), status: 201);
    }

    public function deliver(Request $request, Shipment $shipment): JsonResponse
    {
        $v = $request->validate([
            'received_by_name' => ['required', 'string', 'min:2', 'max:120'],
            'delivery_notes' => ['nullable', 'string', 'max:1000'],
            'delivered_at' => ['nullable', 'date', 'before_or_equal:now'],
            'evidence' => ['nullable', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ], ['evidence.mimetypes' => 'La evidencia debe ser PDF, JPG o PNG.', 'evidence.max' => 'La evidencia supera los 10 MB.']);
        $this->shipments->deliver($shipment, $v['received_by_name'], $v['delivery_notes'] ?? null, $v['delivered_at'] ?? null, $request->file('evidence'), $request->user());

        return ApiResponse::ok($this->detail($shipment, $request));
    }

    public function evidence(Shipment $shipment, AuditLogger $audit): StreamedResponse|JsonResponse
    {
        if (! $shipment->evidence_path || ! Storage::disk('local')->exists($shipment->evidence_path)) {
            return ApiResponse::error('RESOURCE_NOT_FOUND', 'El despacho no tiene evidencia de entrega.', 404);
        }
        $audit->log('shipments.evidence_downloaded', auditableType: 'shipment', auditableId: $shipment->public_id);

        return Storage::disk('local')->download($shipment->evidence_path, 'evidencia-'.$shipment->number.'.'.pathinfo($shipment->evidence_path, PATHINFO_EXTENSION), [
            'Content-Type' => $shipment->evidence_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function reportIncident(Request $request, Shipment $shipment): JsonResponse
    {
        $v = $request->validate(['type' => ['required', Rule::in(array_keys(ShipmentService::INCIDENT_TYPES))], 'description' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->shipments->reportIncident($shipment, $v['type'], $v['description'], $request->user());

        return ApiResponse::ok($this->detail($shipment, $request), status: 201);
    }

    public function resolveIncident(Request $request, Shipment $shipment, string $incident): JsonResponse
    {
        $v = $request->validate(['resolution' => ['required', 'string', 'min:3', 'max:1000']]);
        $i = LogisticsIncident::query()->where('shipment_id', $shipment->getKey())->where('public_id', $incident)->firstOrFail();
        $this->shipments->resolveIncident($i, $v['resolution'], $request->user());

        return ApiResponse::ok($this->detail($shipment, $request));
    }

    // ------------------------------------------------------------------

    private function validateShipment(Request $request, bool $updating): array
    {
        $tenantId = $this->tenant->id();
        $req = $updating ? 'sometimes' : 'required';
        $v = $request->validate([
            'order_public_id' => ['nullable', 'string', 'size:26', Rule::exists('internal_purchase_orders', 'public_id')->where('tenant_id', $tenantId)],
            'carrier_public_id' => ['nullable', 'string', 'size:26', Rule::exists('carriers', 'public_id')->where('tenant_id', $tenantId)],
            'service_type' => ['nullable', Rule::in(array_keys(ShipmentService::SERVICE_TYPES))],
            'origin' => [$req, 'string', 'min:3', 'max:255'],
            'destination' => [$req, 'string', 'min:3', 'max:255'],
            'destination_region' => ['nullable', 'integer', 'between:1,16'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\s()\-]{6,40}$/'],
            'tracking_code' => ['nullable', 'string', 'max:80'],
            'packages' => ['nullable', 'integer', 'between:1,10000'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'planned_pickup_on' => ['nullable', 'date_format:Y-m-d'],
            'planned_delivery_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:planned_pickup_on'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'actual_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (array_key_exists('order_public_id', $v)) {
            $v['internal_purchase_order_id'] = $v['order_public_id'] ? InternalPurchaseOrder::query()->where('public_id', $v['order_public_id'])->value('id') : null;
            unset($v['order_public_id']);
        }
        if (array_key_exists('carrier_public_id', $v)) {
            $v['carrier_id'] = $v['carrier_public_id'] ? Carrier::query()->where('public_id', $v['carrier_public_id'])->value('id') : null;
            unset($v['carrier_public_id']);
        }

        return $v;
    }

    private function validateCarrier(Request $request, ?Carrier $c): array
    {
        return $request->validate([
            'name' => [$c ? 'sometimes' : 'required', 'string', 'min:2', 'max:160', Rule::unique('carriers', 'name')->where('tenant_id', $this->tenant->id())->ignore($c?->getKey())],
            'rut' => ['nullable', 'string', 'max:14', new ValidChileanRut],
            'service_types' => ['nullable', 'array'],
            'service_types.*' => [Rule::in(array_keys(ShipmentService::SERVICE_TYPES))],
            'coverage_regions' => ['nullable', 'array', 'max:16'],
            'coverage_regions.*' => ['integer', 'between:1,16', 'distinct'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email:rfc', 'max:190'],
            // Solo HTTPS y con marcador {code}: no se siguen URLs arbitrarias (§6.5).
            'tracking_url_template' => ['nullable', 'string', 'max:255', 'regex:/^https:\/\/[^\s]+\{code\}[^\s]*$/'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['tracking_url_template.regex' => 'Usa una URL https que incluya {code}, p. ej. https://transportista.cl/seguimiento/{code}.']);
    }

    private function carrier(Carrier $c): array
    {
        return [
            'public_id' => $c->public_id,
            'name' => $c->name,
            'rut' => $c->rut,
            'service_types' => collect($c->service_types ?? [])->map(fn ($s) => ['code' => $s, 'label' => ShipmentService::SERVICE_TYPES[$s] ?? $s])->values(),
            'coverage_regions' => collect($c->coverage_regions ?? [])->map(fn ($r) => ['code' => (int) $r, 'name' => ChileRegion::name((int) $r)])->values(),
            'contact_name' => $c->contact_name,
            'contact_phone' => $c->contact_phone,
            'contact_email' => $c->contact_email,
            'tracking_url_template' => $c->tracking_url_template,
            'is_active' => $c->is_active,
            'notes' => $c->notes,
        ];
    }

    private function summary(Shipment $s): array
    {
        $today = now('America/Santiago')->startOfDay();

        return [
            'public_id' => $s->public_id,
            'number' => $s->number,
            'status' => ['code' => $s->status, 'label' => ShipmentService::STATUSES[$s->status] ?? $s->status],
            'carrier' => $s->carrier ? ['public_id' => $s->carrier->public_id, 'name' => $s->carrier->name] : null,
            'service_type' => $s->service_type ? ['code' => $s->service_type, 'label' => ShipmentService::SERVICE_TYPES[$s->service_type] ?? $s->service_type] : null,
            'origin' => $s->origin,
            'destination' => $s->destination,
            'tracking_code' => $s->tracking_code,
            'tracking_url' => $s->carrier?->tracking_url_template && $s->tracking_code ? str_replace('{code}', rawurlencode($s->tracking_code), $s->carrier->tracking_url_template) : null,
            'planned_delivery_on' => $s->planned_delivery_on?->toDateString(),
            'delivered_at' => $s->delivered_at?->toIso8601String(),
            'late' => in_array($s->status, ['PLANNED', 'IN_TRANSIT', 'FAILED'], true) && $s->planned_delivery_on !== null && $s->planned_delivery_on->lessThan($today),
            'open_incidents' => (int) ($s->open_incidents_count ?? $s->incidents()->where('status', 'OPEN')->count()),
            'order' => $s->order ? ['public_id' => $s->order->public_id, 'number' => $s->order->number] : null,
            'data_origin' => 'client',
        ];
    }

    private function detail(Shipment $s, Request $request): array
    {
        $s->refresh()->load(['carrier', 'order', 'events.user:id,name', 'incidents.reporter:id,name']);
        $tenant = $this->tenant->get();
        $can = fn (string $p) => $request->user()->hasPermission($p, $tenant);
        $open = in_array($s->status, ['PLANNED', 'IN_TRANSIT', 'FAILED'], true);

        return $this->summary($s) + [
            'destination_region' => $s->destination_region ? ['code' => $s->destination_region, 'name' => ChileRegion::name($s->destination_region)] : null,
            'contact_name' => $s->contact_name,
            'contact_phone' => $s->contact_phone,
            'packages' => $s->packages,
            'weight_kg' => $s->weight_kg,
            'planned_pickup_on' => $s->planned_pickup_on?->toDateString(),
            'picked_up_at' => $s->picked_up_at?->toIso8601String(),
            'estimated_cost' => $s->estimated_cost,
            'actual_cost' => $s->actual_cost,
            'received_by_name' => $s->received_by_name,
            'delivery_notes' => $s->delivery_notes,
            'has_evidence' => $s->evidence_path !== null,
            'evidence_url' => $s->evidence_path ? '/api/v1/shipments/'.$s->public_id.'/evidence' : null,
            'notes' => $s->notes,
            'events' => $s->events->sortByDesc('occurred_at')->values()->map(fn ($e) => [
                'status' => $e->status ? ['code' => $e->status, 'label' => ShipmentService::STATUSES[$e->status] ?? $e->status] : null,
                'description' => $e->description,
                'location' => $e->location,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'user' => $e->user?->name,
            ]),
            'incidents' => $s->incidents->sortByDesc('id')->values()->map(fn (LogisticsIncident $i) => [
                'public_id' => $i->public_id,
                'type' => ['code' => $i->type, 'label' => ShipmentService::INCIDENT_TYPES[$i->type] ?? $i->type],
                'description' => $i->description,
                'status' => $i->status,
                'resolution' => $i->resolution,
                'reported_by' => $i->reporter?->name,
                'created_at' => $i->created_at?->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
            ]),
            'actions' => [
                'update' => $can('shipments.update'),
                'transitions' => $can('shipments.update') ? match ($s->status) {
                    'PLANNED' => ['IN_TRANSIT', 'CANCELLED'],
                    'IN_TRANSIT' => ['FAILED', 'RETURNED'],
                    'FAILED' => ['IN_TRANSIT', 'RETURNED', 'CANCELLED'],
                    default => [],
                } : [],
                'deliver' => $open && $can('shipments.confirm_delivery'),
                'report_incident' => $can('shipments.update'),
            ],
        ];
    }
}
