<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Proformas\Models\Proforma;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * The kardex written by documents: an invoice takes its products out of the cash register's warehouse
 * (SALE) and a credit note for returned goods puts them back (RETURN). A product belongs to one warehouse,
 * so a sale can never take units from another one. Services have no stock: only products move.
 * By hand: ENTRY / EXIT with a reason and the mass count (ADJUSTMENT with its AJ-000001). Proformas reserve units
 * (StockReservations) that a sale may not take; with an advance or on credit they take them (PROFORMA).
 */
final class StockLedger
{
    public function __construct(private readonly StockReservations $reservations) {}

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

        // The units leave the cash register's warehouse; a product may also have units in other warehouses.
        $stocks = ItemStock::query()->whereIn('item_id', array_keys($changes))->where('warehouse_id', $warehouse->id)->lockForUpdate()->get()->keyBy('item_id');
        $totals = ItemStock::query()->whereIn('item_id', array_keys($changes))->groupBy('item_id')->selectRaw('item_id, SUM(quantity) as total')->pluck('total', 'item_id');
        $names = Item::query()->whereIn('id', array_keys($changes))->pluck('name', 'id');
        $elsewhere = [];
        $short = [];
        foreach ($changes as $itemId => $change) {
            $stock = $stocks->get($itemId);
            if (bccomp($change, '0', 6) > 0 && ! $stock) {
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
        // Units reserved by other proformas are not sold (the sale's own proforma does not count).
        $ownProforma = Proforma::query()->where('company_id', $sale->company_id)->where('converted_sale_id', $sale->id)->value('id');
        $reserved = $this->reservations->forItems($sale->company_id, array_keys(array_filter($changes, fn (string $change) => bccomp($change, '0', 6) > 0)), $ownProforma);
        $held = [];
        foreach ($reserved as $itemId => $units) {
            $available = bcsub((string) ($totals[$itemId] ?? '0'), $units, 6);
            if (bccomp($available, $changes[$itemId], 6) < 0) {
                $held[] = ['name' => $names[$itemId], 'reserved' => $units, 'available' => $available, 'requested' => $changes[$itemId]];
            }
        }
        if ($held !== []) {
            throw new ApiException(ApiErrorCode::STOCK_RESERVED, 'Están reservados por proformas: '.implode(', ', array_column($held, 'name')).'.', 409, ['items' => $held]);
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
        foreach ($items as $item) {
            $stock = ItemStock::query()->where('item_id', $item->id)->where('warehouse_id', $fallback->id)->lockForUpdate()->first()
                ?? ItemStock::query()->create(['company_id' => $item->company_id, 'item_id' => $item->id, 'warehouse_id' => $fallback->id, 'quantity' => '0']);
            $this->write($stock, $quantities[$item->internal_code], StockMovement::RETURN, $creditNote, $actor, 'Devolución '.$creditNote->full_number);
        }
    }

    /**
     * Internal consumption: supplies used up without being sold (e.g. the polish of a car wash). It leaves
     * the product's own warehouse and may go below zero, because the work is already done; the kardex keeps the
     * module and reference that caused it. Services and products never stocked are skipped.
     */
    public function consume(Item $item, string $units, User $actor, string $note, string $sourceModule, string $sourceReference): void
    {
        if ($item->type !== ItemType::PRODUCT || bccomp($units, '0', 6) <= 0) {
            return;
        }
        $stock = ItemStock::query()->where('item_id', $item->id)->orderByDesc('quantity')->orderBy('id')->lockForUpdate()->first();
        if (! $stock) {
            return;
        }
        $balance = bcsub((string) $stock->quantity, $units, 6);
        $stock->forceFill(['quantity' => $balance])->save();
        StockMovement::query()->create([
            'company_id' => $stock->company_id, 'item_id' => $stock->item_id, 'warehouse_id' => $stock->warehouse_id,
            'type' => StockMovement::CONSUMPTION, 'quantity' => bcmul($units, '-1', 6), 'balance' => $balance,
            'source_module' => $sourceModule, 'source_reference' => $sourceReference, 'user_id' => $actor->id, 'note' => mb_substr($note, 0, 255),
        ]);
    }

    private function write(ItemStock $stock, string $change, string $type, ElectronicDocument $document, User $actor, string $note): void
    {
        $this->record($stock, $change, $type, $actor, $note, ['electronic_document_id' => $document->id]);
    }

    /**
     * A movement by hand: ENTRY adds, EXIT takes out (never the units reserved by proformas, and never below zero
     * unless Sistema allows negative stock).
     */
    public function move(Item $item, string $type, string $units, string $reason, ?string $note, bool $allowNegative, User $actor, ?Warehouse $warehouse = null): StockMovement
    {
        // An entry may open a warehouse for the product; an exit leaves one where it has units.
        $stock = $type === StockMovement::ENTRY && $warehouse !== null ? $this->rowIn($item, $warehouse) : $this->stockOf($item, $warehouse);
        if ($type === StockMovement::EXIT) {
            $free = bcsub($this->totalOf($item), $this->reservations->forItems($item->company_id, [$item->id])[$item->id] ?? '0', 6);
            $available = bccomp($free, (string) $stock->quantity, 6) < 0 ? $free : (string) $stock->quantity;
            if (bccomp($available, $units, 6) < 0 && ! ($allowNegative && bccomp($available, (string) $stock->quantity, 6) === 0)) {
                throw new ApiException(ApiErrorCode::INSUFFICIENT_STOCK, "Sólo hay {$this->units($available)} disponibles de {$item->name}.", 409, ['available' => $available]);
            }
        }
        $change = $type === StockMovement::EXIT ? bcmul($units, '-1', 6) : $units;

        return $this->record($stock, $change, $type, $actor, $note ?: null, ['reason' => $reason]);
    }

    /**
     * Traslado: the units sent leave the origin warehouse and arrive at the destination (opened for the product if it
     * had none there). The kardex keeps both sides: TRANSFER −units in the origin and +units in the destination.
     * Returns the arrival.
     */
    public function transfer(Item $item, ?Warehouse $origin, Warehouse $destination, string $units, ?string $note, User $actor): StockMovement
    {
        $from = $this->stockOf($item, $origin);
        if ($from->warehouse_id === $destination->id) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_IN_WAREHOUSE, "{$item->name} ya está en {$destination->name}: elige otra bodega.", 422);
        }
        if (bccomp((string) $from->quantity, $units, 6) < 0) {
            throw new ApiException(ApiErrorCode::INSUFFICIENT_STOCK, "Sólo hay {$this->units((string) $from->quantity)} de {$item->name} en esa bodega.", 409, ['available' => (string) $from->quantity]);
        }
        $to = $this->rowIn($item, $destination);
        $originName = Warehouse::query()->whereKey($from->warehouse_id)->value('name');
        $this->record($from, bcmul($units, '-1', 6), StockMovement::TRANSFER, $actor, $note ?: 'Traslado a '.$destination->name);

        return $this->record($to, $units, StockMovement::TRANSFER, $actor, $note ?: 'Traslado desde '.$originName);
    }

    /**
     * Mass count: each product gets the counted units; only the ones that change leave an ADJUSTMENT movement, all
     * under one AJ-000001. Nothing is saved if one fails.
     *
     * @param  array<int, array{item: Item, counted: string, warehouse?: ?Warehouse}>  $lines
     */
    public function adjust(Company $company, array $lines, ?string $note, User $actor): StockAdjustment
    {
        Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
        $adjustment = StockAdjustment::query()->create([
            'company_id' => $company->id,
            'number' => ((int) StockAdjustment::query()->where('company_id', $company->id)->max('number')) + 1,
            'note' => $note,
            'user_id' => $actor->id,
        ]);
        $changed = 0;
        foreach ($lines as $line) {
            $stock = $this->stockOf($line['item'], $line['warehouse'] ?? null);
            $change = bcsub($line['counted'], (string) $stock->quantity, 6);
            if (bccomp($change, '0', 6) === 0) {
                continue;
            }
            $this->record($stock, $change, StockMovement::ADJUSTMENT, $actor, $note ?: 'Ajuste masivo '.$adjustment->displayNumber(), ['stock_adjustment_id' => $adjustment->id]);
            $changed++;
        }
        $adjustment->forceFill(['lines_count' => $changed])->save();

        return $adjustment;
    }

    /**
     * A proforma with an advance or on credit takes its products (PROFORMA); it can only take units that are free
     * (stock minus what other proformas reserve).
     */
    public function commitProforma(Proforma $proforma, User $actor): void
    {
        $short = [];
        $lines = $proforma->lines()->with('item')->get()->filter(fn ($line) => $line->item?->type === ItemType::PRODUCT);
        $wanted = [];
        foreach ($lines as $line) {
            $wanted[$line->item_id] = bcadd($wanted[$line->item_id] ?? '0', (string) $line->quantity, 6);
        }
        $reserved = $this->reservations->forItems($proforma->company_id, array_keys($wanted), $proforma->id);
        $stocks = [];
        foreach ($wanted as $itemId => $units) {
            $item = $lines->firstWhere('item_id', $itemId)->item;
            $stocks[$itemId] = ($proforma->warehouse_id ? ItemStock::query()->where('item_id', $itemId)->where('warehouse_id', $proforma->warehouse_id)->lockForUpdate()->first() : null)
                ?? $this->stockOf($item);
            $free = bcsub($this->totalOf($item), $reserved[$itemId] ?? '0', 6);
            $available = bccomp($free, (string) $stocks[$itemId]->quantity, 6) < 0 ? $free : (string) $stocks[$itemId]->quantity;
            if (bccomp($available, $units, 6) < 0) {
                $short[] = ['name' => $item->name, 'available' => $available, 'requested' => $units];
            }
        }
        if ($short !== []) {
            throw new ApiException(ApiErrorCode::INSUFFICIENT_STOCK, 'No hay stock libre para: '.implode(', ', array_column($short, 'name')).'.', 409, ['items' => $short]);
        }
        foreach ($wanted as $itemId => $units) {
            $this->record($stocks[$itemId], bcmul($units, '-1', 6), StockMovement::PROFORMA, $actor, 'Proforma '.$proforma->displayNumber().($proforma->on_credit ? ' a crédito' : ' con anticipo'), ['proforma_id' => $proforma->id]);
        }
        $proforma->forceFill(['stock_committed' => true])->save();
    }

    /** Gives back what a proforma took (it became a sale, was rejected or no longer has an advance or credit). */
    public function releaseProforma(Proforma $proforma, User $actor, string $note): void
    {
        $taken = StockMovement::query()->where('proforma_id', $proforma->id)->whereIn('type', [StockMovement::PROFORMA, StockMovement::PROFORMA_RELEASE])
            ->get(['item_id', 'warehouse_id', 'quantity'])->groupBy(fn ($row) => $row->item_id.':'.$row->warehouse_id)
            ->map(fn ($rows): string => bcmul((string) $rows->sum('quantity'), '-1', 6));
        foreach ($taken as $key => $units) {
            if (bccomp($units, '0', 6) <= 0) {
                continue;
            }
            [$itemId, $warehouseId] = explode(':', (string) $key);
            $stock = ItemStock::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
            if ($stock) {
                $this->record($stock, $units, StockMovement::PROFORMA_RELEASE, $actor, $note.' '.$proforma->displayNumber(), ['proforma_id' => $proforma->id]);
            }
        }
        $proforma->forceFill(['stock_committed' => false])->save();
    }

    /** Before its invoice takes the units, the converted proforma gives back what it had taken. */
    public function releaseProformaForSale(Sale $sale, User $actor): void
    {
        $proforma = Proforma::query()->where('company_id', $sale->company_id)->where('converted_sale_id', $sale->id)->where('stock_committed', true)->lockForUpdate()->first();
        if ($proforma) {
            $this->releaseProforma($proforma, $actor, 'Pasa a la venta');
        }
    }

    /** The product's row in a warehouse, or its main one (the first) when none is given. */
    private function stockOf(Item $item, ?Warehouse $warehouse = null): ItemStock
    {
        $this->assertProduct($item);
        if ($warehouse !== null) {
            return ItemStock::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->first()
                ?? throw new ApiException(ApiErrorCode::ITEM_NOT_IN_WAREHOUSE, "{$item->name} no tiene unidades en {$warehouse->name}.", 422);
        }

        return ItemStock::query()->where('item_id', $item->id)->orderBy('id')->lockForUpdate()->first()
            ?? throw new ApiException(ApiErrorCode::ITEM_NOT_IN_WAREHOUSE, "{$item->name} no tiene bodega: asígnala en Inventario.", 422);
    }

    /** The product's row in a warehouse, opened with 0 units when it has none there. */
    private function rowIn(Item $item, Warehouse $warehouse): ItemStock
    {
        $this->assertProduct($item);

        return ItemStock::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->lockForUpdate()->first()
            ?? ItemStock::query()->create(['company_id' => $item->company_id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '0']);
    }

    /** All the product's units, in every warehouse. */
    private function totalOf(Item $item): string
    {
        return bcadd((string) ItemStock::query()->where('item_id', $item->id)->sum('quantity'), '0', 6);
    }

    private function assertProduct(Item $item): void
    {
        if ($item->type !== ItemType::PRODUCT) {
            throw new ApiException(ApiErrorCode::STOCK_ITEM_NOT_PRODUCT, "{$item->name} es un servicio: no lleva stock.", 422);
        }
    }

    /** @param array<string, mixed> $links */
    private function record(ItemStock $stock, string $change, string $type, User $actor, ?string $note, array $links = []): StockMovement
    {
        $balance = bcadd((string) $stock->quantity, $change, 6);
        $stock->forceFill(['quantity' => $balance])->save();

        return StockMovement::query()->create([
            'company_id' => $stock->company_id, 'item_id' => $stock->item_id, 'warehouse_id' => $stock->warehouse_id,
            'type' => $type, 'quantity' => $change, 'balance' => $balance, 'user_id' => $actor->id,
            'note' => $note === null ? null : mb_substr($note, 0, 255), ...$links,
        ]);
    }

    private function units(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
