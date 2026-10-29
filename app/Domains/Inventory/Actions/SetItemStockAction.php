<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Catalog\Models\Item;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Models\User;

/**
 * Writes the stock a user typed by hand. The difference is kept as a movement (INITIAL on creation,
 * ADJUSTMENT afterwards), so the future kardex has history from day one. A product lives in one
 * warehouse: moving it to another empties the previous one with its own movement.
 */
final class SetItemStockAction
{
    public function execute(Item $item, Warehouse $warehouse, string $quantity, User $actor, bool $initial = false): void
    {
        foreach ($item->stocks()->where('warehouse_id', '<>', $warehouse->id)->get() as $other) {
            $this->write($item, $other, '0', $actor, StockMovement::ADJUSTMENT, 'Cambio de bodega');
        }

        $stock = $item->stocks()->firstOrNew(['warehouse_id' => $warehouse->id], ['company_id' => $item->company_id, 'quantity' => '0']);
        $this->write($item, $stock, $quantity, $actor, $initial ? StockMovement::INITIAL : StockMovement::ADJUSTMENT, $initial ? 'Stock inicial' : 'Ajuste manual');
    }

    private function write(Item $item, ItemStock $stock, string $quantity, User $actor, string $type, string $note): void
    {
        $change = bcsub($quantity, (string) ($stock->quantity ?? '0'), 6);
        if (bccomp($change, '0', 6) === 0 && $stock->exists) {
            return;
        }
        $stock->forceFill(['quantity' => $quantity])->save();
        StockMovement::query()->create([
            'company_id' => $item->company_id, 'item_id' => $item->id, 'warehouse_id' => $stock->warehouse_id,
            'type' => $type, 'quantity' => $change, 'balance' => $quantity, 'user_id' => $actor->id, 'note' => $note,
        ]);
    }
}
