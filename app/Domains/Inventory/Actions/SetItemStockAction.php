<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Catalog\Models\Item;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Models\User;

/**
 * Writes the stock a user typed by hand. The difference is kept as a movement (INITIAL on creation,
 * ADJUSTMENT afterwards), so the kardex has history from day one. This is the product's main warehouse:
 * changing it takes all its units out of the previous one (with its own movement) and into the new one. Once the
 * product has units in several warehouses (Stock › Traslado), they are managed only from Stock.
 */
final class SetItemStockAction
{
    public function execute(Item $item, Warehouse $warehouse, string $quantity, User $actor, bool $initial = false): void
    {
        if ($item->stocks()->count() > 1) {
            return;
        }
        $stock = $item->stocks()->lockForUpdate()->first()
            ?? new ItemStock(['company_id' => $item->company_id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '0']);

        if ($stock->exists && $stock->warehouse_id !== $warehouse->id) {
            if (bccomp((string) $stock->quantity, '0', 6) !== 0) {
                $this->record($item, $stock->warehouse_id, bcsub('0', (string) $stock->quantity, 6), '0', $actor, StockMovement::ADJUSTMENT, 'Cambio a la bodega '.$warehouse->name);
            }
            $stock->forceFill(['warehouse_id' => $warehouse->id, 'quantity' => '0']);
        }

        $change = bcsub($quantity, (string) $stock->quantity, 6);
        $moved = $stock->isDirty('warehouse_id');
        if (bccomp($change, '0', 6) === 0 && $stock->exists && ! $moved) {
            return;
        }
        $stock->forceFill(['quantity' => $quantity])->save();
        $note = $initial ? 'Stock inicial' : ($moved ? 'Cambio de bodega' : 'Ajuste manual');
        $this->record($item, $warehouse->id, $change, $quantity, $actor, $initial ? StockMovement::INITIAL : StockMovement::ADJUSTMENT, $note);
    }

    private function record(Item $item, int $warehouseId, string $change, string $balance, User $actor, string $type, string $note): void
    {
        StockMovement::query()->create([
            'company_id' => $item->company_id, 'item_id' => $item->id, 'warehouse_id' => $warehouseId,
            'type' => $type, 'quantity' => $change, 'balance' => $balance, 'user_id' => $actor->id, 'note' => $note,
        ]);
    }
}
