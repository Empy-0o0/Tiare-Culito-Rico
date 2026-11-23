<?php

namespace App\Services\Inventory;

use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inventario por producto y bodega (CLAUDE.md §5.7).
 * - El saldo vive en inventory_items y se actualiza con bloqueo de fila en la misma transacción que el movimiento.
 * - Los movimientos son inmutables; un error se corrige con un ajuste (con motivo).
 * - No se permite stock negativo.
 */
class InventoryService
{
    public const IN_TYPES = ['IN', 'ADJUST_IN', 'TRANSFER_IN'];

    public const OUT_TYPES = ['OUT', 'ADJUST_OUT', 'TRANSFER_OUT'];

    public const LABELS = [
        'IN' => 'Entrada', 'OUT' => 'Salida', 'ADJUST_IN' => 'Ajuste positivo', 'ADJUST_OUT' => 'Ajuste negativo',
        'TRANSFER_IN' => 'Traspaso recibido', 'TRANSFER_OUT' => 'Traspaso enviado',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{unit_cost?: ?string, lot?: ?string, expires_on?: ?string, reason?: ?string, reference_type?: ?string, reference_id?: ?string}  $opts
     */
    public function move(Product $product, Warehouse $warehouse, string $type, string $quantity, User $user, array $opts = []): InventoryMovement
    {
        if (! isset(self::LABELS[$type])) {
            throw ValidationException::withMessages(['type' => 'Tipo de movimiento no válido.']);
        }
        if (Money::cmp($quantity, '0') <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser mayor que cero.']);
        }
        if (str_starts_with($type, 'ADJUST') && trim((string) ($opts['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'Un ajuste requiere motivo (conteo físico, merma, error de registro…).']);
        }
        if (! $warehouse->is_active) {
            throw ValidationException::withMessages(['warehouse_public_id' => 'La bodega está inactiva.']);
        }

        return DB::transaction(function () use ($product, $warehouse, $type, $quantity, $user, $opts) {
            $item = $this->lockItem($product, $warehouse);
            $out = in_array($type, self::OUT_TYPES, true);
            $balance = $out ? Money::sub((string) $item->quantity_on_hand, $quantity) : Money::add((string) $item->quantity_on_hand, $quantity);
            if (Money::cmp($balance, '0') < 0) {
                throw ValidationException::withMessages(['quantity' => 'Stock insuficiente en '.$warehouse->name.': hay '.rtrim(rtrim(Money::round((string) $item->quantity_on_hand, 4), '0'), '.').'.']);
            }
            $item->update(['quantity_on_hand' => Money::round($balance, 4)]);

            $movement = InventoryMovement::create([
                'inventory_item_id' => $item->getKey(),
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => Money::round($balance, 4),
                'unit_cost' => $opts['unit_cost'] ?? null,
                'lot' => $opts['lot'] ?? null,
                'expires_on' => $opts['expires_on'] ?? null,
                'reason' => $opts['reason'] ?? null,
                'reference_type' => $opts['reference_type'] ?? 'manual',
                'reference_id' => $opts['reference_id'] ?? null,
                'created_by' => $user->getKey(),
            ]);
            $this->audit->log('inventory.moved', $user->getKey(), 'inventory_movement', $movement->public_id, ['type' => $type, 'quantity' => $quantity, 'warehouse' => $warehouse->public_id]);

            return $movement;
        });
    }

    /** Traspaso entre bodegas: dos movimientos enlazados en una transacción. @return array{0: InventoryMovement, 1: InventoryMovement} */
    public function transfer(Product $product, Warehouse $from, Warehouse $to, string $quantity, User $user, ?string $reason = null): array
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_warehouse_public_id' => 'Elige una bodega de destino distinta.']);
        }

        return DB::transaction(function () use ($product, $from, $to, $quantity, $user, $reason) {
            $ref = (string) \Illuminate\Support\Str::ulid();
            $out = $this->move($product, $from, 'TRANSFER_OUT', $quantity, $user, ['reason' => $reason ?? 'Traspaso a '.$to->name, 'reference_type' => 'transfer', 'reference_id' => $ref]);
            $in = $this->move($product, $to, 'TRANSFER_IN', $quantity, $user, ['reason' => $reason ?? 'Traspaso desde '.$from->name, 'reference_type' => 'transfer', 'reference_id' => $ref]);

            return [$out, $in];
        });
    }

    /** Ingresa a bodega lo recibido conforme en una recepción, solo para ítems ligados a un producto del catálogo. */
    public function receiveFromGoodsReceipt(GoodsReceipt $receipt, Warehouse $warehouse, User $user): int
    {
        $count = 0;
        $order = $receipt->order()->first();
        $receipt->loadMissing('items.orderItem');
        foreach ($receipt->items as $line) {
            $requestItem = $line->orderItem->purchase_request_item_id ? \App\Models\PurchaseRequestItem::query()->find($line->orderItem->purchase_request_item_id) : null;
            if (! $requestItem?->product_id || Money::cmp((string) $line->quantity_received, '0') <= 0) {
                continue;
            }
            $unitCost = Money::round(Money::mul((string) $line->orderItem->unit_price, (string) ($order->exchange_rate_to_clp ?: '1')));
            $this->move(Product::query()->findOrFail($requestItem->product_id), $warehouse, 'IN', (string) $line->quantity_received, $user, [
                'unit_cost' => $unitCost,
                'reason' => 'Recepción de la orden '.$order->number,
                'reference_type' => 'goods_receipt',
                'reference_id' => $receipt->public_id,
            ]);
            $count++;
        }

        return $count;
    }

    public function setLevels(InventoryItem $item, ?string $min, ?string $max, ?string $location, User $user): InventoryItem
    {
        if ($min !== null && $max !== null && Money::cmp($max, $min) < 0) {
            throw ValidationException::withMessages(['max_quantity' => 'El máximo no puede ser menor que el mínimo.']);
        }
        $item->update(['min_quantity' => $min, 'max_quantity' => $max, 'location' => $location]);
        $this->audit->log('inventory.levels_updated', $user->getKey(), 'inventory_item', (string) $item->getKey(), compact('min', 'max'));

        return $item;
    }

    /** Sugerencia de reposición: hasta el máximo (o el mínimo si no hay máximo). */
    public static function reorderSuggestion(InventoryItem $item): ?string
    {
        if ($item->min_quantity === null || Money::cmp((string) $item->quantity_on_hand, (string) $item->min_quantity) >= 0) {
            return null;
        }
        $target = (string) ($item->max_quantity ?? $item->min_quantity);

        return Money::round(Money::sub($target, (string) $item->quantity_on_hand), 4);
    }

    private function lockItem(Product $product, Warehouse $warehouse): InventoryItem
    {
        $q = fn () => InventoryItem::query()->where('product_id', $product->getKey())->where('warehouse_id', $warehouse->getKey());
        $item = $q()->lockForUpdate()->first();
        if (! $item) {
            InventoryItem::query()->firstOrCreate(['product_id' => $product->getKey(), 'warehouse_id' => $warehouse->getKey()], ['quantity_on_hand' => 0]);
            $item = $q()->lockForUpdate()->firstOrFail();
        }

        return $item;
    }
}
