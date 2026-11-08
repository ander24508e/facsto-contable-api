<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Models\CarwashCategory;
use App\Domains\Carwash\Models\Service;
use App\Domains\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tarifas reads Inventario: every item (service or product) of a category turned on in the Lavadora has its entry,
 * created here the first time it is seen. Items the Lavadora itself creates (prices per vehicle) never show.
 * Inventario is not changed at all: the Lavadora only reads its items.
 */
final class Rates
{
    public const DEFAULT_DURATION = 30;

    /** @return list<int> */
    public function categoryIds(int $companyId): array
    {
        return CarwashCategory::query()->where('company_id', $companyId)->pluck('category_id')->all();
    }

    /** Creates the missing entries for the items of the turned-on categories (idempotent). */
    public function sync(int $companyId): void
    {
        $categories = $this->categoryIds($companyId);
        if ($categories === []) {
            return;
        }
        $missing = Item::query()
            ->where('company_id', $companyId)
            ->whereNull('managed_by')
            ->whereIn('category_id', $categories)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('carwash_services')->whereColumn('carwash_services.item_id', 'items.id'))
            ->pluck('id');
        $now = now();
        $rows = $missing->map(fn (int $id): array => [
            'public_id' => (string) Str::uuid7(), 'company_id' => $companyId, 'item_id' => $id,
            'duration_minutes' => self::DEFAULT_DURATION, 'created_at' => $now, 'updated_at' => $now,
        ])->all();
        if ($rows !== []) {
            DB::table('carwash_services')->insertOrIgnore($rows);
        }
    }

    /** Entries shown in Tarifas: items of the turned-on categories, not created by the Lavadora. */
    public function visible(int $companyId): Builder
    {
        $categories = $this->categoryIds($companyId);

        return Service::query()->where('company_id', $companyId)
            ->whereHas('item', fn ($item) => $item->whereNull('managed_by')->whereIn('category_id', $categories));
    }
}
