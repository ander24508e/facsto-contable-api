<?php

namespace App\Domains\Cash\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Money put into (IN) or taken out of (OUT) an open cash register, with its reason. */
#[Fillable(['company_id', 'cash_session_id', 'type', 'amount', 'concept', 'created_by'])]
class CashMovement extends Model
{
    public const IN = 'IN';

    public const OUT = 'OUT';

    protected static function booted(): void
    {
        static::creating(fn (self $movement) => $movement->public_id ??= (string) Str::uuid7());
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
