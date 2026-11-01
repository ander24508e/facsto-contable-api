<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One change of stock (the kardex): signed quantity and the resulting balance. Never edited. SALE and
 * RETURN carry the invoice or credit note that caused them; PURCHASE carries the purchase of Inversiones.
 */
#[Fillable(['company_id', 'item_id', 'warehouse_id', 'type', 'quantity', 'balance', 'electronic_document_id', 'purchase_id', 'user_id', 'note'])]
class StockMovement extends Model
{
    public const INITIAL = 'INITIAL';

    public const ADJUSTMENT = 'ADJUSTMENT';

    public const SALE = 'SALE';

    public const RETURN = 'RETURN';

    public const PURCHASE = 'PURCHASE';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(fn (self $movement) => $movement->public_id ??= (string) Str::uuid7());
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(\App\Domains\Purchases\Models\Purchase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'balance' => 'decimal:6'];
    }
}
