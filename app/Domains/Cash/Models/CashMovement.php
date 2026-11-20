<?php

namespace App\Domains\Cash\Models;

use App\Models\User;
use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Money put into (IN) or taken out of (OUT) an open cash register, with its reason. `source` tells typed
 * movements (MANUAL) from the cash of an invoice (SALE) and the cash given back by a return (REFUND).
 */
#[Fillable(['company_id', 'cash_session_id', 'type', 'source', 'amount', 'concept', 'sale_id', 'electronic_document_id', 'created_by'])]
class CashMovement extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'cash-sessions';
    }

    /** It announces its cash session. */
    public function livePublicId(): ?string
    {
        return app(Realtime::class)->publicIdOf(CashSession::class, $this->cash_session_id);
    }

    public function liveAction(string $action): string
    {
        return 'updated';
    }

    public const IN = 'IN';

    public const OUT = 'OUT';

    public const MANUAL = 'MANUAL';

    public const SALE = 'SALE';

    public const REFUND = 'REFUND';

    /** Installments collected from a customer (accounts receivable). */
    public const CREDIT = 'CREDIT';

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
