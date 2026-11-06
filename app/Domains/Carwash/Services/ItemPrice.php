<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Catalog\Models\Item;
use App\Http\Resources\Api\V1\ItemResource;
use Illuminate\Http\Request;

/**
 * The final price of a FACSTO item (with its taxes), computed by the catalog itself: the Lavadora never
 * keeps its own price, so a price change in Inventario is seen here at once.
 */
final class ItemPrice
{
    public function final(Item $item): string
    {
        $item->loadMissing('taxes.definition');

        return (string) (new ItemResource($item))->toArray(new Request)['finalPrice'];
    }
}
