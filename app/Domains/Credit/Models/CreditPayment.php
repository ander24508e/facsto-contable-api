<?php

namespace App\Domains\Credit\Models;

use App\Domains\Cash\Models\CashMovement;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A payment of installments: collected from a customer (sale, accounts receivable) or paid to a
 * supplier (purchase, accounts payable). A collection enters the sale's cash register.
 */
#[Fillable(['company_id', 'sale_id', 'purchase_id', 'amount', 'notes', 'cash_movement_id', 'created_by'])]
class CreditPayment extends Model
{
    use AnnouncesChanges;

    /** A payment announces the sale or the purchase it pays (receivables and payables). */
    public function liveResource(): string
    {
        return $this->sale_id ? 'sales' : 'purchases';
    }

    public function livePublicId(): ?string
    {
        return $this->sale_id
            ? app(Realtime::class)->publicIdOf(Sale::class, $this->sale_id)
            : app(Realtime::class)->publicIdOf(Purchase::class, $this->purchase_id);
    }

    public function liveAction(string $action): string
    {
        return 'updated';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $payment) => $payment->public_id ??= (string) Str::uuid7());
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
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
