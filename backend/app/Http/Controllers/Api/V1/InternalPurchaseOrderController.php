<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Procurement\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProcurementPresenter as P;
use App\Models\InternalPurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\Procurement\PurchaseOrderService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Órdenes de compra internas y recepciones (CLAUDE.md §5.4 pasos 8–10). */
class InternalPurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $page = InternalPurchaseOrder::query()
            ->with(['supplier.organization', 'request'])
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)
                    ->orWhereHas('supplier.organization', fn ($o) => $o->where('legal_name', 'like', $like))
                    ->orWhereHas('request', fn ($r) => $r->where('title', 'like', $like)->orWhere('number', 'like', $like)));
            })
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate((int) ($v['per_page'] ?? 25));

        return ApiResponse::paginated($page, collect($page->items())->map(fn ($o) => P::orderSummary($o))->values());
    }

    public function show(Request $request, InternalPurchaseOrder $order): JsonResponse
    {
        return ApiResponse::ok(P::orderDetail($order->load(['supplier.organization', 'request', 'issuer']), $request->user()));
    }

    public function store(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $v = $request->validate([
            'payer_name' => ['required', 'string', 'min:2', 'max:200'],
            'invoice_holder_name' => ['required', 'string', 'min:2', 'max:200'],
            'invoice_holder_rut' => ['nullable', 'string', 'max:14', new \App\Rules\ValidChileanRut],
            'contractual_buyer' => ['required', 'string', 'min:2', 'max:200'],
            'receiver_name' => ['required', 'string', 'min:2', 'max:200'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'expected_delivery_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = $this->orders->issue($purchaseRequest, $v, $request->user());

        return ApiResponse::ok(P::orderDetail($order->load(['supplier.organization', 'request', 'issuer']), $request->user()), status: 201);
    }

    public function markSent(Request $request, InternalPurchaseOrder $order): JsonResponse
    {
        $this->orders->markSent($order, $request->user());

        return $this->show($request, $order->refresh());
    }

    public function cancel(Request $request, InternalPurchaseOrder $order): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->orders->cancel($order, $v['reason'], $request->user());

        return $this->show($request, $order->refresh());
    }

    public function destroy(Request $request, InternalPurchaseOrder $order): JsonResponse
    {
        $this->orders->delete($order, $request->user());

        return ApiResponse::ok(['deleted' => true, 'message' => "Orden {$order->number} eliminada."]);
    }

    public function receive(Request $request, InternalPurchaseOrder $order): JsonResponse
    {
        $today = CarbonImmutable::now('America/Santiago')->toDateString();
        $v = $request->validate([
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'document_reference' => ['nullable', 'string', 'max:80'],
            'has_incident' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000', 'required_if:has_incident,true'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.line_number' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0'],
            'items.*.quantity_rejected' => ['nullable', 'numeric', 'min:0'],
            'items.*.incident_note' => ['nullable', 'string', 'max:500'],
            // Opcional: ingresar lo recibido conforme a una bodega (Fase 5).
            'warehouse_public_id' => ['nullable', 'string', 'size:26'],
        ], ['notes.required_if' => 'Describe la incidencia.', 'received_on.before_or_equal' => 'La fecha de recepción no puede ser futura.']);

        $warehouse = null;
        if (! empty($v['warehouse_public_id'])) {
            if (! $request->user()->hasPermission('inventory.manage', app(\App\Support\CurrentTenant::class)->get())) {
                return ApiResponse::error('FORBIDDEN', 'Ingresar a bodega requiere permiso de inventario.', 403);
            }
            $warehouse = \App\Models\Warehouse::query()->where('public_id', $v['warehouse_public_id'])->where('is_active', true)->first();
            if (! $warehouse) {
                return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['warehouse_public_id' => ['Bodega no encontrada o inactiva.']]);
            }
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $v, $request, $warehouse) {
            $receipt = $this->orders->receive($order, $v, $request->user());
            if ($warehouse) {
                app(\App\Services\Inventory\InventoryService::class)->receiveFromGoodsReceipt($receipt, $warehouse, $request->user());
            }
        });

        return $this->show($request, $order->refresh());
    }
}
