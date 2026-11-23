<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Procurement\PurchaseRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProcurementPresenter as P;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Services\Procurement\PurchaseRequestService;
use App\Services\Procurement\QuotationService;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Solicitudes de compra privadas y su flujo (CLAUDE.md §5.4). Permisos por ruta (middleware `permission`);
 * el tenant se aplica con BelongsToTenant y el route-model binding por public_id (registro ajeno = 404).
 */
class PurchaseRequestController extends Controller
{
    public function __construct(
        private readonly PurchaseRequestService $requests,
        private readonly QuotationService $quotations,
        private readonly CurrentTenant $tenant,
    ) {}

    public function references(): JsonResponse
    {
        return ApiResponse::ok([
            'statuses' => PurchaseRequestStatus::options(),
            'order_statuses' => PurchaseOrderStatus::options(),
            'priorities' => collect(PurchaseRequest::PRIORITIES)->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'default_weights' => QuotationService::DEFAULT_WEIGHTS,
            'default_tax_rate' => 19,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(PurchaseRequestStatus::class)],
            'mine' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = PurchaseRequest::query()
            ->with(['requester:id,name', 'customer.organization', 'selectedQuotation'])
            ->withCount(['items', 'quotations'])
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('number', 'like', $like));
            })
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('mine'), fn ($q) => $q->where('requested_by', $request->user()->getKey()))
            ->orderByDesc('id')
            ->paginate((int) ($v['per_page'] ?? 25));

        $counts = PurchaseRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ApiResponse::paginated($page, collect($page->items())->map(fn ($r) => P::requestSummary($r))->values(), ['status_counts' => $counts]);
    }

    public function store(Request $request): JsonResponse
    {
        $r = $this->requests->create($this->validateRequest($request, true), $request->user());

        return ApiResponse::ok($this->detail($r, $request), status: 201);
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        return ApiResponse::ok($this->detail($purchaseRequest, $request));
    }

    public function update(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $user = $request->user();
        if ($purchaseRequest->requested_by !== $user->getKey() && ! $user->hasPermission('purchase_requests.approve', $this->tenant->get())) {
            return ApiResponse::error('FORBIDDEN', 'Solo quien creó la solicitud o un aprobador puede editarla.', 403);
        }
        $this->requests->update($purchaseRequest, $this->validateRequest($request, false), $user);

        return ApiResponse::ok($this->detail($purchaseRequest, $request));
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->requests->submit($purchaseRequest, $request->user());

        return ApiResponse::ok($this->detail($purchaseRequest, $request));
    }

    public function startQuoting(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->requests->startQuoting($purchaseRequest, $request->user());

        return ApiResponse::ok($this->detail($purchaseRequest, $request));
    }

    public function cancel(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $user = $request->user();
        $own = $purchaseRequest->requested_by === $user->getKey() && $purchaseRequest->status->isEditable();
        if (! $own && ! $user->hasPermission('purchase_requests.reject', $this->tenant->get())) {
            return ApiResponse::error('FORBIDDEN', 'Solo quien creó la solicitud (antes de cotizar) o un aprobador puede anularla.', 403);
        }
        $this->requests->cancel($purchaseRequest, $v['reason'], $user);

        return ApiResponse::ok($this->detail($purchaseRequest, $request));
    }

    public function requestQuote(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $v = $request->validate([
            'supplier_public_id' => ['required', 'string', 'size:26'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $supplier = Supplier::query()->where('public_id', $v['supplier_public_id'])->firstOrFail();
        $this->quotations->requestQuote($purchaseRequest, $supplier, $v['due_on'] ?? null, $v['notes'] ?? null, $request->user());

        return ApiResponse::ok($this->detail($purchaseRequest, $request), status: 201);
    }

    public function registerQuotation(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $v = $request->validate([
            'supplier_public_id' => ['required', 'string', 'size:26'],
            'reference' => ['nullable', 'string', 'max:80'],
            'currency' => ['nullable', Rule::in(['CLP', 'USD', 'EUR', 'CLF'])],
            'exchange_rate_to_clp' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'exchange_rate_source' => ['nullable', 'string', 'max:120'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'prices_include_tax' => ['nullable', 'boolean'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'delivery_days' => ['nullable', 'integer', 'between:0,365'],
            'warranty_months' => ['nullable', 'integer', 'between:0,240'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'received_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.line_number' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'items.*.offered_brand' => ['nullable', 'string', 'max:120'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        $supplier = Supplier::query()->where('public_id', $v['supplier_public_id'])->firstOrFail();
        $this->quotations->register($purchaseRequest, $supplier, $v, $request->user());

        return ApiResponse::ok($this->detail($purchaseRequest, $request), status: 201);
    }

    public function discardQuotation(Request $request, PurchaseRequest $purchaseRequest, string $quotation): JsonResponse
    {
        $q = SupplierQuotation::query()->where('purchase_request_id', $purchaseRequest->getKey())->where('public_id', $quotation)->firstOrFail();
        $this->quotations->discard($q, $request->user());

        return ApiResponse::ok($this->detail($purchaseRequest, $request));
    }

    public function destroyQuotation(Request $request, PurchaseRequest $purchaseRequest, string $quotation): JsonResponse
    {
        $q = SupplierQuotation::query()->where('purchase_request_id', $purchaseRequest->getKey())->where('public_id', $quotation)->firstOrFail();
        $this->quotations->delete($q, $request->user());

        return ApiResponse::ok($this->detail($purchaseRequest->refresh(), $request));
    }

    public function compare(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $v = $request->validate([
            'weights' => ['nullable', 'array'],
            'weights.price' => ['nullable', 'numeric', 'between:0,1'],
            'weights.delivery' => ['nullable', 'numeric', 'between:0,1'],
            'weights.supplier' => ['nullable', 'numeric', 'between:0,1'],
        ]);
        if ($purchaseRequest->status !== PurchaseRequestStatus::Quoting) {
            return ApiResponse::error('VALIDATION_FAILED', 'Solo se comparan cotizaciones de una solicitud en cotización.', 422);
        }
        $this->quotations->compare($purchaseRequest, $v['weights'] ?? null, $request->user());

        return ApiResponse::ok($this->detail($purchaseRequest, $request), status: 201);
    }

    public function propose(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $v = $request->validate([
            'quotation_public_id' => ['required', 'string', 'size:26'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);
        $q = SupplierQuotation::query()->where('purchase_request_id', $purchaseRequest->getKey())->where('public_id', $v['quotation_public_id'])->firstOrFail();
        // Si no se elige la recomendada, se exige justificación.
        $recommended = $purchaseRequest->comparisons()->first()?->recommended_quotation_id;
        // Una recomendada que luego se eliminó (TD-031) ya no obliga a justificar.
        if ($recommended && $recommended !== $q->getKey() && SupplierQuotation::query()->whereKey($recommended)->exists() && trim((string) ($v['justification'] ?? '')) === '') {
            return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['justification' => ['Explica por qué no eliges la cotización recomendada.']]);
        }
        $this->requests->propose($purchaseRequest, $q, $v['justification'] ?? null, $request->user());

        return ApiResponse::ok($this->detail($purchaseRequest, $request));
    }

    public function decide(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $v = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $approve = $v['decision'] === 'APPROVED';
        $permission = $approve ? 'purchase_requests.approve' : 'purchase_requests.reject';
        if (! $request->user()->hasPermission($permission, $this->tenant->get())) {
            return ApiResponse::error('FORBIDDEN', 'No tienes permiso para realizar esta acción.', 403);
        }
        $this->requests->decide($purchaseRequest, $approve, $v['comment'] ?? null, $request->user());

        return ApiResponse::ok($this->detail($purchaseRequest, $request));
    }

    // ------------------------------------------------------------------

    private function detail(PurchaseRequest $r, Request $request): array
    {
        $r->refresh()->load([
            'items.product', 'requester:id,name', 'proposer:id,name', 'customer.organization', 'selectedQuotation',
            'quotationRequests.supplier.organization', 'quotations.supplier.organization', 'quotations.items.requestItem',
            'approvals.user:id,name', 'comparisons.generator:id,name', 'order.supplier.organization', 'order.request',
        ]);

        return P::requestDetail($r, $request->user());
    }

    /** @return array<string, mixed> */
    private function validateRequest(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $tenantId = $this->tenant->id();

        return $request->validate([
            'title' => [$req, 'string', 'min:3', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'customer_public_id' => ['nullable', 'string', 'size:26', Rule::exists('customers', 'public_id')->where('tenant_id', $tenantId)],
            'cost_center' => ['nullable', 'string', 'max:80'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'needed_by' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'priority' => ['nullable', Rule::in(array_keys(PurchaseRequest::PRIORITIES))],
            'items' => [$req, 'array', 'min:1', 'max:200'],
            'items.*.product_public_id' => ['nullable', 'string', 'size:26', Rule::exists('products', 'public_id')->where('tenant_id', $tenantId)],
            'items.*.description' => ['required_without:items.*.product_public_id', 'nullable', 'string', 'min:2', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'items.*.unit_code' => ['nullable', 'string', Rule::exists('units_of_measure', 'code')],
            'items.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ], [
            'needed_by.after_or_equal' => 'La fecha requerida no puede ser pasada.',
            'items.*.description.required_without' => 'Describe el ítem o elige un producto del catálogo.',
        ]);
    }
}
