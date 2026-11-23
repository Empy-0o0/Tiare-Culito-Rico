<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierOffering;
use App\Models\UnitOfMeasure;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Oferta del proveedor: insumos/productos y servicios que entrega, con capacidad declarada (TD-026).
 * Lectura con suppliers.read; altas y cambios con suppliers.update. La capacidad es un dato que informa
 * el proveedor: se muestra como "declarada" y con su fecha de confirmación, no como verificada por TIARE.
 */
class SupplierOfferingController extends Controller
{
    public function index(Request $request, Supplier $supplier): JsonResponse
    {
        Gate::authorize('view', $supplier);
        $items = SupplierOffering::query()
            ->where('supplier_id', $supplier->getKey())
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->with(['category:id,public_id,name', 'product:id,public_id,name,sku', 'unit:id,code,name'])
            ->orderBy('kind')
            ->orderBy('name')
            ->get();

        return ApiResponse::ok($items->map(fn (SupplierOffering $o) => $this->present($o))->values(), [
            'kinds' => collect(SupplierOffering::KINDS)->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'periods' => collect(SupplierOffering::PERIODS)->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'note' => 'Capacidad declarada por el proveedor. Confírmala antes de comprometer una entrega.',
        ]);
    }

    public function store(Request $request, Supplier $supplier, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $supplier);
        $data = $this->validated($request);
        $offering = SupplierOffering::create($data + ['supplier_id' => $supplier->getKey()]);
        $audit->log('suppliers.offering_created', auditableType: 'supplier', auditableId: $supplier->public_id, metadata: ['offering' => $offering->public_id]);

        return ApiResponse::ok($this->present($offering->load(['category', 'product', 'unit'])), status: 201);
    }

    public function update(Request $request, Supplier $supplier, string $offering, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $supplier);
        $model = $this->find($supplier, $offering);
        $model->fill($this->validated($request, $model))->save();
        $audit->log('suppliers.offering_updated', auditableType: 'supplier', auditableId: $supplier->public_id, metadata: ['offering' => $model->public_id, 'fields' => array_keys($model->getChanges())]);

        return ApiResponse::ok($this->present($model->load(['category', 'product', 'unit'])));
    }

    public function destroy(Supplier $supplier, string $offering, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $supplier);
        $model = $this->find($supplier, $offering);
        $model->delete();
        $audit->log('suppliers.offering_deleted', auditableType: 'supplier', auditableId: $supplier->public_id, metadata: ['offering' => $model->public_id, 'name' => $model->name]);

        return ApiResponse::ok(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SupplierOffering $current = null): array
    {
        $req = $current ? 'sometimes' : 'required';
        $v = $request->validate([
            'kind' => [$req, Rule::in(array_keys(SupplierOffering::KINDS))],
            'name' => [$req, 'string', 'min:2', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category_public_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'product_public_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'unit_code' => ['sometimes', 'nullable', 'string', 'max:16', Rule::exists('units_of_measure', 'code')],
            'capacity_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999999999999'],
            'capacity_period' => ['sometimes', 'nullable', Rule::in(array_keys(SupplierOffering::PERIODS))],
            'min_order_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999999999999'],
            'lead_time_days' => ['sometimes', 'nullable', 'integer', 'between:0,365'],
            'capacity_notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'capacity_confirmed_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Una capacidad sin período no se puede interpretar (¿5.000 por día o por mes?).
        $quantity = array_key_exists('capacity_quantity', $v) ? $v['capacity_quantity'] : $current?->capacity_quantity;
        $period = array_key_exists('capacity_period', $v) ? $v['capacity_period'] : $current?->capacity_period;
        if ($quantity !== null && ! $period) {
            throw ValidationException::withMessages(['capacity_period' => 'Indica el período de la capacidad (por día, semana, mes o año).']);
        }

        $data = collect($v)->except(['category_public_id', 'product_public_id', 'unit_code'])->all();
        // Referencias por public_id, siempre dentro de la empresa activa (global scope): una ajena da 422.
        if (array_key_exists('category_public_id', $v)) {
            $data['category_id'] = $this->resolve(Category::class, $v['category_public_id'], 'category_public_id', 'Categoría no encontrada.');
        }
        if (array_key_exists('product_public_id', $v)) {
            $data['product_id'] = $this->resolve(Product::class, $v['product_public_id'], 'product_public_id', 'Producto del catálogo no encontrado.');
        }
        if (array_key_exists('unit_code', $v)) {
            $data['unit_of_measure_id'] = $v['unit_code'] ? UnitOfMeasure::query()->where('code', $v['unit_code'])->value('id') : null;
        }

        return $data;
    }

    /** @param class-string<Model> $model */
    private function resolve(string $model, ?string $publicId, string $field, string $message): ?int
    {
        if (! $publicId) {
            return null;
        }
        $id = $model::query()->where('public_id', $publicId)->value('id');
        if (! $id) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return (int) $id;
    }

    private function find(Supplier $supplier, string $publicId): SupplierOffering
    {
        return SupplierOffering::query()->where('supplier_id', $supplier->getKey())->where('public_id', $publicId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(SupplierOffering $o): array
    {
        return [
            'public_id' => $o->public_id,
            'kind' => ['code' => $o->kind, 'label' => SupplierOffering::KINDS[$o->kind] ?? $o->kind],
            'name' => $o->name,
            'description' => $o->description,
            'category' => $o->category ? ['public_id' => $o->category->public_id, 'name' => $o->category->name] : null,
            'product' => $o->product ? ['public_id' => $o->product->public_id, 'name' => $o->product->name, 'sku' => $o->product->sku] : null,
            'unit' => $o->unit ? ['code' => $o->unit->code, 'name' => $o->unit->name] : null,
            'capacity' => [
                'quantity' => $o->capacity_quantity,
                'period' => $o->capacity_period ? ['code' => $o->capacity_period, 'label' => SupplierOffering::PERIODS[$o->capacity_period] ?? $o->capacity_period] : null,
                'min_order_quantity' => $o->min_order_quantity,
                'lead_time_days' => $o->lead_time_days,
                'notes' => $o->capacity_notes,
                'confirmed_on' => $o->capacity_confirmed_on?->toDateString(),
                'data_origin' => 'supplier_declared',
            ],
            'is_active' => $o->is_active,
            'updated_at' => $o->updated_at?->toIso8601String(),
        ];
    }
}
