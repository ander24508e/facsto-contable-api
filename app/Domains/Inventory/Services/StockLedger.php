<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Models\Item;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * The kardex written by documents: an invoice takes its products out of the cash register's warehouse
 * (SALE) and a credit note for returned goods puts them back (RETURN). A product belongs to one warehouse,
 * so a sale can never take units from another one. Services have no stock.
 */
final class StockLedger
{
    /**
     * Leaves the invoice's SALE movements equal to its lines. On the first numbering that is the whole
     * quantity; after a correction only the difference is written, so the kardex never counts twice.
     */
    public function syncInvoice(ElectronicDocument $invoice, Sale $sale, Warehouse $warehouse, bool $allowNegative, User $actor): void
    {
        $wanted = [];
        foreach ($sale->details as $line) {
            if ($line->item?->type === ItemType::PRODUCT) {
                $wanted[$line->item_id] = bcadd($wanted[$line->item_id] ?? '0', (string) $line->quantity, 6);
            }
        }
        $taken = StockMovement::query()->where('electronic_document_id', $invoice->id)->where('type', StockMovement::SALE)
            ->get(['item_id', 'quantity'])->groupBy('item_id')
            ->map(fn ($rows): string => bcmul((string) $rows->sum('quantity'), '-1', 6))->all();

        $changes = [];
        foreach (array_unique([...array_keys($wanted), ...array_keys($taken)]) as $itemId) {
            $change = bcsub($wanted[$itemId] ?? '0', $taken[$itemId] ?? '0', 6);
            if (bccomp($change, '0', 6) !== 0) {
                $changes[$itemId] = $change;
            }
        }
        if ($changes === []) {
            return;
        }

        $stocks = ItemStock::query()->whereIn('item_id', array_keys($changes))->lockForUpdate()->get()->keyBy('item_id');
        $names = Item::query()->whereIn('id', array_keys($changes))->pluck('name', 'id');
        $elsewhere = [];
        $short = [];
        foreach ($changes as $itemId => $change) {
            $stock = $stocks->get($itemId);
            if (bccomp($change, '0', 6) > 0 && (! $stock || $stock->warehouse_id !== $warehouse->id)) {
                $elsewhere[] = $names[$itemId];
            } elseif (! $allowNegative && bccomp($change, '0', 6) > 0 && bccomp((string) $stock->quantity, $change, 6) < 0) {
                $short[] = ['name' => $names[$itemId], 'available' => (string) $stock->quantity, 'requested' => $change];
            }
        }
        if ($elsewhere !== []) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_IN_WAREHOUSE, 'Hay productos que no están en la bodega de la caja ('.$warehouse->name.'): '.implode(', ', $elsewhere).'.', 422, ['items' => $elsewhere]);
        }
        if ($short !== []) {
            throw new ApiException(ApiErrorCode::INSUFFICIENT_STOCK, 'No hay stock suficiente en '.$warehouse->name.' para: '.implode(', ', array_column($short, 'name')).'.', 409, ['items' => $short]);
        }

        $corrected = $taken !== [];
        foreach ($changes as $itemId => $change) {
            $stock = $stocks->get($itemId);
            if (! $stock) {
                // Only a correction that gives units back can reach here without a stock row: it is the
                // warehouse the invoice took them from.
                $stock = ItemStock::query()->create(['company_id' => $sale->company_id, 'item_id' => $itemId, 'warehouse_id' => $warehouse->id, 'quantity' => '0']);
            }
            $this->write($stock, bcmul($change, '-1', 6), StockMovement::SALE, $invoice, $actor, ($corrected ? 'Corrección de la factura ' : 'Factura ').$invoice->full_number);
        }
    }

    /** A credit note for returned goods puts each product back in the warehouse it belongs to. */
    public function returnCreditNote(ElectronicDocument $creditNote, Warehouse $fallback, User $actor): void
    {
        $quantities = [];
        foreach ($creditNote->lines as $line) {
            $quantities[$line->item_code] = bcadd($quantities[$line->item_code] ?? '0', (string) $line->quantity, 6);
        }
        $items = Item::query()->where('company_id', $creditNote->company_id)->whereIn('internal_code', array_keys($quantities))
            ->where('type', ItemType::PRODUCT->value)->get();
        $stocks = ItemStock::query()->whereIn('item_id', $items->pluck('id'))->lockForUpdate()->get()->keyBy('item_id');
        foreach ($items as $item) {
            $stock = $stocks->get($item->id)
                ?? ItemStock::query()->create(['company_id' => $item->company_id, 'item_id' => $item->id, 'warehouse_id' => $fallback->id, 'quantity' => '0']);
            $this->write($stock, $quantities[$item->internal_code], StockMovement::RETURN, $creditNote, $actor, 'Devolución '.$creditNote->full_number);
        }
    }

    private function write(ItemStock $stock, string $change, string $type, ElectronicDocument $document, User $actor, string $note): void
    {
        $balance = bcadd((string) $stock->quantity, $change, 6);
        $stock->forceFill(['quantity' => $balance])->save();
        StockMovement::query()->create([
            'company_id' => $stock->company_id, 'item_id' => $stock->item_id, 'warehouse_id' => $stock->warehouse_id,
            'type' => $type, 'quantity' => $change, 'balance' => $balance, 'electronic_document_id' => $document->id,
            'user_id' => $actor->id, 'note' => $note,
        ]);
    }
}
