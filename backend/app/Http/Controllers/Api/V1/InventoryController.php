<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\PublicProcurement\ChileRegion;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Bodegas, stock, movimientos y reposición (CLAUDE.md §5.7). Lectura: inventory.read; escritura: inventory.manage. */
class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory, private readonly CurrentTenant $tenant) {}

    public function references(): JsonResponse
    {
        return ApiResponse::ok([
            'movement_types' => collect(InventoryService::LABELS)->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
            'warehouses' => Warehouse::query()->orderBy('name')->get()->map(fn ($w) => $this->warehouse($w))->values(),
        ]);
    }

    public function warehouses(): JsonResponse
    {
        $rows = Warehouse::query()->withCount('items')->orderBy('name')->get();

        return ApiResponse::ok($rows->map(fn ($w) => $this->warehouse($w) + ['items_count' => $w->items_count])->values());
    }

    public function storeWarehouse(Request $request, AuditLogger $audit): JsonResponse
    {
        $v = $this->validateWarehouse($request, null);
        $w = Warehouse::create($v);
        $audit->log('inventory.warehouse_created', auditableType: 'warehouse', auditableId: $w->public_id);

        return ApiResponse::ok($this->warehouse($w), status: 201);
    }

    public function updateWarehouse(Request $request, Warehouse $warehouse, AuditLogger $audit): JsonResponse
    {
        $v = $this->validateWarehouse($request, $warehouse);
        $warehouse->update($v);
        $audit->log('inventory.warehouse_updated', auditableType: 'warehouse', auditableId: $warehouse->public_id, metadata: ['fields' => array_keys($v)]);

        return ApiResponse::ok($this->warehouse($warehouse));
    }

    public function stock(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'warehouse_public_id' => ['nullable', 'string', 'size:26'],
            'low_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $page = InventoryItem::query()
            ->with(['product.unit', 'warehouse'])
            ->when($v['warehouse_public_id'] ?? null, fn ($q, $id) => $q->whereHas('warehouse', fn ($w) => $w->where('public_id', $id)))
            ->when($v['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->whereHas('product', fn ($p) => $p->where('name', 'like', $like)->orWhere('sku', 'like', $like));
            })
            ->when($request->boolean('low_only'), fn ($q) => $q->whereNotNull('min_quantity')->whereColumn('quantity_on_hand', '<', 'min_quantity'))
            ->orderByRaw('CASE WHEN min_quantity IS NOT NULL AND quantity_on_hand < min_quantity THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->paginate((int) ($v['per_page'] ?? 25));

        $low = InventoryItem::query()->whereNotNull('min_quantity')->whereColumn('quantity_on_hand', '<', 'min_quantity')->count();

        return ApiResponse::paginated($page, collect($page->items())->map(fn ($i) => $this->item($i))->values(), ['low_stock_count' => $low]);
    }

    public function movements(Request $request): JsonResponse
    {
        $v = $request->validate([
            'warehouse_public_id' => ['nullable', 'string', 'size:26'],
            'product_public_id' => ['nullable', 'string', 'size:26'],
            'type' => ['nullable', Rule::in(array_keys(InventoryService::LABELS))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $page = InventoryMovement::query()
            ->with(['item.product', 'item.warehouse', 'author:id,name'])
            ->when($v['warehouse_public_id'] ?? null, fn ($q, $id) => $q->whereHas('item.warehouse', fn ($w) => $w->where('public_id', $id)))
            ->when($v['product_public_id'] ?? null, fn ($q, $id) => $q->whereHas('item.product', fn ($p) => $p->where('public_id', $id)))
            ->when($v['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('id')
            ->paginate((int) ($v['per_page'] ?? 30));

        return ApiResponse::paginated($page, collect($page->items())->map(fn (InventoryMovement $m) => [
            'public_id' => $m->public_id,
            'type' => ['code' => $m->type, 'label' => InventoryService::LABELS[$m->type] ?? $m->type, 'direction' => in_array($m->type, InventoryService::OUT_TYPES, true) ? 'out' : 'in'],
            'product' => ['public_id' => $m->item->product->public_id, 'name' => $m->item->product->name, 'sku' => $m->item->product->sku],
            'warehouse' => ['public_id' => $m->item->warehouse->public_id, 'name' => $m->item->warehouse->name],
            'quantity' => $m->quantity,
            'balance_after' => $m->balance_after,
            'unit_cost' => $m->unit_cost,
            'lot' => $m->lot,
            'expires_on' => $m->expires_on?->toDateString(),
            'reason' => $m->reason,
            'reference_type' => $m->reference_type,
            'created_by' => $m->author?->name,
            'created_at' => $m->created_at?->toIso8601String(),
        ])->values());
    }

    public function move(Request $request): JsonResponse
    {
        $tenantId = $this->tenant->id();
        $v = $request->validate([
            'type' => ['required', Rule::in(['IN', 'OUT', 'ADJUST_IN', 'ADJUST_OUT', 'TRANSFER'])],
            'product_public_id' => ['required', 'string', 'size:26', Rule::exists('products', 'public_id')->where('tenant_id', $tenantId)],
            'warehouse_public_id' => ['required', 'string', 'size:26', Rule::exists('warehouses', 'public_id')->where('tenant_id', $tenantId)],
            'to_warehouse_public_id' => ['required_if:type,TRANSFER', 'nullable', 'string', 'size:26', Rule::exists('warehouses', 'public_id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lot' => ['nullable', 'string', 'max:60'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $product = Product::query()->where('public_id', $v['product_public_id'])->firstOrFail();
        $from = Warehouse::query()->where('public_id', $v['warehouse_public_id'])->firstOrFail();
        if ($product->kind === 'SERVICE') {
            return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['product_public_id' => ['Los servicios no se inventarían.']]);
        }

        if ($v['type'] === 'TRANSFER') {
            $to = Warehouse::query()->where('public_id', $v['to_warehouse_public_id'])->firstOrFail();
            $this->inventory->transfer($product, $from, $to, (string) $v['quantity'], $request->user(), $v['reason'] ?? null);
        } else {
            $this->inventory->move($product, $from, $v['type'], (string) $v['quantity'], $request->user(), array_intersect_key($v, array_flip(['unit_cost', 'lot', 'expires_on', 'reason'])));
        }

        $item = InventoryItem::query()->with(['product.unit', 'warehouse'])->where('product_id', $product->getKey())->where('warehouse_id', $from->getKey())->firstOrFail();

        return ApiResponse::ok($this->item($item), status: 201);
    }

    public function levels(Request $request, string $warehouse, string $product): JsonResponse
    {
        $v = $request->validate([
            'min_quantity' => ['nullable', 'numeric', 'min:0'],
            'max_quantity' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:60'],
        ]);
        $item = InventoryItem::query()->with(['product.unit', 'warehouse'])
            ->whereHas('warehouse', fn ($w) => $w->where('public_id', $warehouse))
            ->whereHas('product', fn ($p) => $p->where('public_id', $product))->firstOrFail();
        $this->inventory->setLevels($item, isset($v['min_quantity']) ? (string) $v['min_quantity'] : null, isset($v['max_quantity']) ? (string) $v['max_quantity'] : null, $v['location'] ?? null, $request->user());

        return ApiResponse::ok($this->item($item->refresh()->load(['product.unit', 'warehouse'])));
    }

    // ------------------------------------------------------------------

    private function validateWarehouse(Request $request, ?Warehouse $w): array
    {
        $tenantId = $this->tenant->id();

        return $request->validate([
            'code' => [$w ? 'sometimes' : 'required', 'string', 'max:24', 'regex:/^[A-Za-z0-9\-_]+$/', Rule::unique('warehouses', 'code')->where('tenant_id', $tenantId)->ignore($w?->getKey())],
            'name' => [$w ? 'sometimes' : 'required', 'string', 'min:2', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'region_code' => ['nullable', 'integer', 'between:1,16'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'El código solo admite letras, números, guion y guion bajo.']);
    }

    private function warehouse(Warehouse $w): array
    {
        return [
            'public_id' => $w->public_id,
            'code' => $w->code,
            'name' => $w->name,
            'address' => $w->address,
            'region' => $w->region_code ? ['code' => $w->region_code, 'name' => ChileRegion::name($w->region_code)] : null,
            'is_active' => $w->is_active,
        ];
    }

    private function item(InventoryItem $i): array
    {
        $low = $i->min_quantity !== null && (float) $i->quantity_on_hand < (float) $i->min_quantity;

        return [
            'product' => ['public_id' => $i->product->public_id, 'name' => $i->product->name, 'sku' => $i->product->sku, 'unit' => $i->product->unit?->code],
            'warehouse' => ['public_id' => $i->warehouse->public_id, 'name' => $i->warehouse->name, 'code' => $i->warehouse->code],
            'quantity_on_hand' => $i->quantity_on_hand,
            'min_quantity' => $i->min_quantity,
            'max_quantity' => $i->max_quantity,
            'location' => $i->location,
            'low_stock' => $low,
            'reorder_suggestion' => InventoryService::reorderSuggestion($i),
            'data_origin' => 'client',
        ];
    }
}
