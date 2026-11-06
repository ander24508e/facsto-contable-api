<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Catalog\Models\Item;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * Turns an amount in a measure (500 ml) into units of the item's own unit (Caneca of 20 L → 0.025) using the
 * item's content per unit. Without a measure the amount is already in the item's unit (e.g. 1 paño).
 */
final class ItemContent
{
    /** Measure => [family, factor to the family's base]. */
    public const MEASURES = ['ml' => ['volume', '1'], 'L' => ['volume', '1000'], 'g' => ['mass', '1'], 'kg' => ['mass', '1000']];

    /** Whether the amount of a measure can be taken from this item. */
    public function accepts(Item $item, ?string $measure): bool
    {
        if ($measure === null) {
            return true;
        }
        if (! isset(self::MEASURES[$measure]) || $item->content_unit === null || $item->content_quantity === null) {
            return false;
        }

        return self::MEASURES[$measure][0] === self::MEASURES[$item->content_unit][0];
    }

    public function units(Item $item, string $amount, ?string $measure): string
    {
        if ($measure === null) {
            return bcadd($amount, '0', 6);
        }
        if (! $this->accepts($item, $measure)) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, "«{$item->name}» no tiene un contenido por unidad en una medida compatible con {$measure}.", 422);
        }
        $used = bcmul($amount, self::MEASURES[$measure][1], 12);
        $content = bcmul((string) $item->content_quantity, self::MEASURES[$item->content_unit][1], 12);

        return bcdiv($used, $content, 6);
    }
}
