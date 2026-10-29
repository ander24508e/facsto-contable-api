<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Alternate (wholesale) price: from `min_quantity` units on, each unit costs `price`. */
#[Fillable(['company_id', 'item_id', 'min_quantity', 'price'])]
class ItemPriceTier extends Model
{
    protected function casts(): array
    {
        return ['min_quantity' => 'decimal:6', 'price' => 'decimal:6'];
    }
}
