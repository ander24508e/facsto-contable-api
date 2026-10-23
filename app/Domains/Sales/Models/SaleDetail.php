<?php

namespace App\Domains\Sales\Models;

use App\Domains\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'sale_id', 'item_id', 'item_code', 'auxiliary_code', 'item_name', 'unit', 'quantity', 'unit_price', 'discount_amount', 'subtotal', 'taxable_base', 'tax_total', 'total'])]
class SaleDetail extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $detail) => $detail->public_id ??= (string) Str::uuid7());
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(SaleDetailTax::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:6',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'taxable_base' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}
