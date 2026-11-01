<?php

namespace App\Domains\Purchases\Models;

use App\Domains\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'purchase_id', 'item_id', 'item_code', 'item_name', 'quantity', 'unit_cost', 'tax_percentage', 'subtotal', 'tax_total', 'total'])]
class PurchaseLine extends Model
{
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'tax_percentage' => 'decimal:4', 'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2'];
    }
}
