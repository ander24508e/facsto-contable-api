<?php

namespace App\Domains\Sales\Models;

use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One installment of a sale on credit (accounts receivable). */
#[Fillable(['company_id', 'sale_id', 'number', 'due_date', 'amount', 'paid_amount'])]
class SaleInstallment extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'sales';
    }

    /** It announces its sale. */
    public function livePublicId(): ?string
    {
        return app(Realtime::class)->publicIdOf(Sale::class, $this->sale_id);
    }

    public function liveAction(string $action): string
    {
        return 'updated';
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];
    }
}
