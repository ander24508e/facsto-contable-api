<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Proformas\Models\Proforma;
use Illuminate\Support\Facades\DB;

/**
 * Units reserved by proformas (shown in yellow): the lines of products of every open proforma (pending or accepted)
 * that has not expired and does not take stock yet (no advance, not on credit). Reserving never changes the stock;
 * a sale may not take reserved units.
 */
final class StockReservations
{
    /**
     * @param  list<int>  $itemIds
     * @return array<int, string> item id => reserved units
     */
    public function forItems(int $companyId, array $itemIds, ?int $exceptProformaId = null): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('proforma_lines as l')
            ->join('proformas as p', 'p.id', '=', 'l.proforma_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->where('p.company_id', $companyId)
            ->whereIn('p.status', Proforma::OPEN)
            ->where('p.stock_committed', false)
            ->whereNull('p.converted_sale_id')
            ->whereDate('p.expiration_date', '>=', today())
            ->where('i.type', ItemType::PRODUCT->value)
            ->whereIn('l.item_id', $itemIds)
            ->when($exceptProformaId, fn ($query) => $query->where('p.id', '<>', $exceptProformaId))
            ->groupBy('l.item_id')
            ->selectRaw('l.item_id, SUM(l.quantity) as reserved')
            ->pluck('reserved', 'item_id')
            ->map(fn ($units) => bcadd((string) $units, '0', 6))
            ->all();
    }
}
