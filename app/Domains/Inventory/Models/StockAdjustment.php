<?php

namespace App\Domains\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A mass stock count saved at once (AJ-000001 per company); each changed product has its ADJUSTMENT movement. */
#[Fillable(['company_id', 'number', 'note', 'lines_count', 'user_id'])]
class StockAdjustment extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(fn (self $adjustment) => $adjustment->public_id ??= (string) Str::uuid7());
    }

    public function displayNumber(): string
    {
        return 'AJ-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
