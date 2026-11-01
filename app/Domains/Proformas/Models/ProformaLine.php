<?php

namespace App\Domains\Proformas\Models;

use App\Domains\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'proforma_id', 'item_id', 'item_code', 'item_name', 'unit', 'quantity', 'unit_price', 'discount_amount', 'subtotal', 'taxable_base', 'tax_total', 'total', 'taxes'])]
class ProformaLine extends Model
{
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'discount_amount' => 'decimal:2', 'subtotal' => 'decimal:2',
            'taxable_base' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'taxes' => 'array',
        ];
    }
}
