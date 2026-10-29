<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One change of stock (base of the kardex): signed quantity and the resulting balance. Never edited. */
#[Fillable(['company_id', 'item_id', 'warehouse_id', 'type', 'quantity', 'balance', 'user_id', 'note'])]
class StockMovement extends Model
{
    public const INITIAL = 'INITIAL';

    public const ADJUSTMENT = 'ADJUSTMENT';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(fn (self $movement) => $movement->public_id ??= (string) Str::uuid7());
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'balance' => 'decimal:6'];
    }
}
