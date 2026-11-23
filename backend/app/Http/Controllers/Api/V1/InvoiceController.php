<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProcurementPresenter as P;
use App\Models\InternalPurchaseOrder;
use App\Models\Invoice;
use App\Services\Procurement\InvoiceService;
use App\Services\Procurement\ProcurementIndicatorsService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Facturas de órdenes internas, pago informado e indicadores de compras (CLAUDE.md §5.4 pasos 11–12). */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::in(['REGISTERED', 'PAID', 'OVERDUE'])],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $today = CarbonImmutable::now('America/Santiago')->toDateString();
        $page = Invoice::query()
            ->with(['supplier.organization', 'order'])
            ->when(($v['status'] ?? null) === 'OVERDUE', fn ($q) => $q->where('status', 'REGISTERED')->whereNotNull('due_on')->where('due_on', '<', $today))
            ->when(in_array($v['status'] ?? null, ['REGISTERED', 'PAID'], true), fn ($q) => $q->where('status', $v['status']))
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('number', 'like', $like)
                    ->orWhereHas('supplier.organization', fn ($o) => $o->where('legal_name', 'like', $like))
                    ->orWhereHas('order', fn ($o) => $o->where('number', 'like', $like)));
            })
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderByDesc('id')
            ->paginate((int) ($v['per_page'] ?? 25));

        return ApiResponse::paginated($page, collect($page->items())->map(fn ($i) => P::invoice($i))->values());
    }

    public function store(Request $request, InternalPurchaseOrder $order): JsonResponse
    {
        $v = $request->validate([
            'number' => ['required', 'string', 'max:40', 'regex:/^[0-9A-Za-z\-]+$/'],
            'document_type' => ['nullable', Rule::in(array_keys(Invoice::TYPES))],
            'issued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now('America/Santiago')->toDateString()],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'currency' => ['nullable', Rule::in(['CLP', 'USD', 'EUR', 'CLF'])],
            'net_amount' => ['required', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['number.regex' => 'El folio solo admite números, letras y guiones.']);
        $this->invoices->register($order, $v, $request->user());

        return ApiResponse::ok(P::orderDetail($order->refresh()->load(['supplier.organization', 'request', 'issuer']), $request->user()), status: 201);
    }

    public function pay(Request $request, Invoice $invoice): JsonResponse
    {
        $v = $request->validate([
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now('America/Santiago')->toDateString()],
            'payment_reference' => ['nullable', 'string', 'max:120'],
            'paid_by_label' => ['required', 'string', 'min:2', 'max:200'],
        ]);
        $this->invoices->informPayment($invoice, $v, $request->user());

        return ApiResponse::ok(P::invoice($invoice->refresh()->load(['supplier.organization', 'order'])));
    }

    public function indicators(Request $request, ProcurementIndicatorsService $service): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = isset($v['to']) ? CarbonImmutable::parse($v['to'], 'America/Santiago') : CarbonImmutable::now('America/Santiago');
        $from = isset($v['from']) ? CarbonImmutable::parse($v['from'], 'America/Santiago') : $to->subDays(89);
        if ($from->diffInDays($to) > 731) {
            return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['from' => ['El período máximo es de dos años.']]);
        }

        return ApiResponse::ok($service->compute($from, $to));
    }
}
