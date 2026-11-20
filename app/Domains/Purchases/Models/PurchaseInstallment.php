<?php

namespace App\Domains\Purchases\Models;

use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One installment of a purchase on credit (accounts payable). */
#[Fillable(['company_id', 'purchase_id', 'number', 'due_date', 'amount', 'paid_amount'])]
class PurchaseInstallment extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'purchases';
    }

    /** It announces its purchase. */
    public function livePublicId(): ?string
    {
        return app(Realtime::class)->publicIdOf(Purchase::class, $this->purchase_id);
    }

    public function liveAction(string $action): string
    {
        return 'updated';
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];
    }
}
