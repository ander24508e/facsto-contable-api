<?php

namespace App\Domains\Sales\Models;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'sale_id', 'fiscal_payment_method_id', 'method_code', 'method_name', 'amount', 'reference', 'term', 'time_unit'])]
class SalePaymentMethod extends Model
{
    public function fiscalPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(FiscalPaymentMethod::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'term' => 'decimal:2'];
    }
}
