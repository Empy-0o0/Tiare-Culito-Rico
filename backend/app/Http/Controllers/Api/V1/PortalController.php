<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\PublicProcurement\ChileRegion;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\InternalPurchaseOrder;
use App\Models\Organization;
use App\Models\PortalAccess;
use App\Models\PurchaseRequest;
use App\Models\QuotationRequest;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\SupplierOffering;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal de clientes y proveedores (TD-028). Solo lectura y solo el registro propio.
 *
 * Qué ve cada rol:
 * - Cliente: su organización y contactos, su estado y plan, y sus solicitudes de compra.
 * - Proveedor: su organización y contactos, su estado, rubros y cobertura, sus insumos/servicios y
 *   capacidad, sus documentos con vigencia, las solicitudes de cotización dirigidas a él y sus órdenes.
 * Nunca: evaluaciones internas, notas internas, datos de otros clientes/proveedores ni de la empresa.
 */
class PortalController extends Controller
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function profile(Request $request): JsonResponse
    {
        $tenant = $this->current->get();
        $access = $tenant ? PortalAccess::query()->where('user_id', $request->user()->getKey())->first() : null;
        if (! $access) {
            return ApiResponse::error('RESOURCE_NOT_FOUND', 'Tu cuenta no tiene un portal de cliente o proveedor en esta empresa.', 404);
        }
        $permission = $access->party_type === PortalAccess::CUSTOMER ? 'portal.customer' : 'portal.supplier';
        if (! $request->user()->hasPermission($permission, $tenant)) {
            return ApiResponse::error('FORBIDDEN', 'El plan de la empresa no incluye este portal.', 403);
        }

        $data = $access->party_type === PortalAccess::CUSTOMER
            ? $this->customer($access->customer()->with('organization.contacts')->firstOrFail())
            : $this->supplier($access->supplier()->with(['organization.contacts', 'categories'])->firstOrFail());

        return ApiResponse::ok(['type' => $access->party_type, 'company' => ['name' => $tenant->name]] + $data, [
            'data_origin' => 'tenant',
            'note' => 'Información registrada por '.$tenant->name.'. Si algo no está correcto, contáctalos para actualizarlo.',
        ]);
    }

    /** @return array<string, mixed> */
    private function customer(Customer $c): array
    {
        $requests = PurchaseRequest::query()->where('customer_id', $c->getKey())->latest('id')->limit(50)->get();

        return [
            'organization' => $this->organization($c->organization),
            'status' => ['code' => $c->status?->value, 'label' => $c->status?->label()],
            'plan' => $c->plan,
            'purchase_requests' => $requests->map(fn (PurchaseRequest $r) => [
                'number' => $r->number,
                'title' => $r->title,
                'status' => ['code' => $r->status?->value, 'label' => $r->status?->label()],
                'needed_by' => $r->needed_by?->format('Y-m-d'),
                'currency' => $r->currency,
                'estimated_total' => $r->estimated_total,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function supplier(Supplier $s): array
    {
        $offerings = SupplierOffering::query()->where('supplier_id', $s->getKey())->where('is_active', true)->with('unit')->orderBy('name')->get();
        $documents = SupplierDocument::query()->where('supplier_id', $s->getKey())->whereNull('archived_at')->orderBy('expires_on')->get();
        $quotes = QuotationRequest::query()->where('supplier_id', $s->getKey())->with('request:id,number,title')->latest('id')->limit(50)->get();
        $orders = InternalPurchaseOrder::query()->where('supplier_id', $s->getKey())->latest('id')->limit(50)->get();

        return [
            'organization' => $this->organization($s->organization),
            'status' => ['code' => $s->status?->value, 'label' => $s->status?->label()],
            'categories' => $s->categories->pluck('name')->values(),
            'coverage_regions' => collect($s->coverage_regions ?? [])->map(fn ($r) => ChileRegion::name((int) $r))->filter()->values(),
            'payment_terms' => $s->payment_terms,
            'lead_time_days' => $s->lead_time_days,
            'offerings' => $offerings->map(fn (SupplierOffering $o) => [
                'kind' => SupplierOffering::KINDS[$o->kind] ?? $o->kind,
                'name' => $o->name,
                'unit' => $o->unit?->name,
                'capacity_quantity' => $o->capacity_quantity,
                'capacity_period' => $o->capacity_period ? (SupplierOffering::PERIODS[$o->capacity_period] ?? null) : null,
                'min_order_quantity' => $o->min_order_quantity,
                'lead_time_days' => $o->lead_time_days,
                'capacity_confirmed_on' => $o->capacity_confirmed_on?->toDateString(),
            ])->values(),
            'documents' => $documents->map(fn (SupplierDocument $d) => [
                'type' => SupplierDocument::TYPES[$d->type] ?? $d->type,
                'name' => $d->name,
                'expires_on' => $d->expires_on?->toDateString(),
                'validity' => $d->validityStatus(),
            ])->values(),
            'quotation_requests' => $quotes->map(fn (QuotationRequest $q) => [
                'request_number' => $q->request?->number,
                'title' => $q->request?->title,
                'status' => $q->status,
                'due_on' => $q->due_on?->toDateString(),
                'created_at' => $q->created_at?->toIso8601String(),
            ])->values(),
            'purchase_orders' => $orders->map(fn (InternalPurchaseOrder $o) => [
                'number' => $o->number,
                'status' => ['code' => $o->status?->value, 'label' => $o->status?->label()],
                'currency' => $o->currency,
                'total' => $o->total,
                'issued_at' => $o->issued_at?->toIso8601String(),
                'expected_delivery_on' => $o->expected_delivery_on?->format('Y-m-d'),
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function organization(?Organization $o): array
    {
        return [
            'legal_name' => $o?->legal_name,
            'trade_name' => $o?->trade_name,
            'rut' => $o?->rut,
            'email' => $o?->email,
            'phone' => $o?->phone,
            'address' => $o?->address,
            'commune' => $o?->commune,
            'region' => $o?->region_code ? ChileRegion::name((int) $o->region_code) : null,
            'contacts' => $o?->contacts->map(fn ($c) => ['full_name' => $c->full_name, 'position' => $c->position, 'email' => $c->email, 'phone' => $c->phone])->values() ?? [],
        ];
    }
}
