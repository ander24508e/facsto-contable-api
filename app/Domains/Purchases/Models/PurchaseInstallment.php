<?php

namespace App\Domains\Purchases\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One installment of a purchase on credit (accounts payable). */
#[Fillable(['company_id', 'purchase_id', 'number', 'due_date', 'amount', 'paid_amount'])]
class PurchaseInstallment extends Model
{
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];
    }
}
