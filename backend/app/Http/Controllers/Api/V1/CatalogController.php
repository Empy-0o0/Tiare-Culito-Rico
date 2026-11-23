<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\OrganizationKind;
use App\Domain\Crm\SupplierStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Catálogo de productos/servicios, categorías y listas de referencia. */
class CatalogController extends Controller
{
    /** Enumeraciones para formularios (no requiere permisos de escritura). */
    public function references(): JsonResponse
    {
        $map = fn (array $cases) => collect($cases)->map(fn ($c) => ['code' => $c->value, 'label' => $c->label()])->values();

        return ApiResponse::ok([
            'organization_kinds' => $map(OrganizationKind::cases()),
            'customer_statuses' => $map(CustomerStatus::cases()),
            'supplier_statuses' => $map(SupplierStatus::cases()),
            'customer_plans' => ['Start', 'Growth', 'Managed', 'Public', 'SaaS'],
            'units_of_measure' => UnitOfMeasure::query()->orderBy('name')->get(['code', 'name']),
        ]);
    }

    public function categories(): JsonResponse
    {
        Gate::authorize('viewAny', Category::class);

        return ApiResponse::ok(CategoryResource::collection(Category::query()->orderBy('name')->get()));
    }

    public function storeCategory(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', Category::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', Rule::unique('categories', 'name')->where('tenant_id', app(CurrentTenant::class)->id())],
            'code' => ['nullable', 'string', 'max:40'],
        ], ['name.unique' => 'Ya existe una categoría con este nombre.']);

        $category = Category::create($data);
        $audit->log('categories.created', auditableType: 'category', auditableId: $category->public_id);

        return ApiResponse::ok(new CategoryResource($category), status: 201);
    }

    public function products(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', Rule::in(Product::KINDS)],
            'category_public_id' => ['nullable', 'string', 'size:26'],
            'active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = Product::query()
            ->with(['unit', 'category'])
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('brand', 'like', $like));
            })
            ->when($v['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($v['category_public_id'] ?? null, fn ($q, $c) => $q->where('category_id', Category::query()->where('public_id', $c)->value('id') ?? 0))
            ->when(array_key_exists('active', $v) && $v['active'] !== null, fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate((int) ($v['per_page'] ?? 25));

        return ApiResponse::paginated($page, ProductResource::collection($page->items()));
    }

    public function storeProduct(ProductRequest $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', Product::class);
        $product = Product::create($this->productAttributes($request->validated()));
        $audit->log('products.created', auditableType: 'product', auditableId: $product->public_id);

        return ApiResponse::ok(new ProductResource($product->load(['unit', 'category'])), status: 201);
    }

    public function showProduct(Product $product): JsonResponse
    {
        Gate::authorize('view', $product);

        return ApiResponse::ok(new ProductResource($product->load(['unit', 'category'])));
    }

    public function updateProduct(ProductRequest $request, Product $product, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $product);
        $product->fill($this->productAttributes($request->validated()))->save();
        $audit->log('products.updated', auditableType: 'product', auditableId: $product->public_id, metadata: ['fields' => array_keys($request->validated())]);

        return ApiResponse::ok(new ProductResource($product->refresh()->load(['unit', 'category'])));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function productAttributes(array $data): array
    {
        if (array_key_exists('unit_code', $data)) {
            $data['unit_of_measure_id'] = UnitOfMeasure::query()->where('code', $data['unit_code'])->value('id');
            unset($data['unit_code']);
        }
        if (array_key_exists('category_public_id', $data)) {
            $data['category_id'] = $data['category_public_id'] ? Category::query()->where('public_id', $data['category_public_id'])->value('id') : null;
            unset($data['category_public_id']);
        }

        return $data;
    }
}
