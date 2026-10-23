<?php

namespace App\Domains\Sales\Models;

use App\Domains\Catalog\Models\Tax;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'sale_detail_id', 'tax_id', 'tax_type', 'sri_code', 'percentage_code', 'tax_name', 'percentage', 'taxable_base', 'tax_amount'])]
class SaleDetailTax extends Model
{
    public function detail(): BelongsTo
    {
        return $this->belongsTo(SaleDetail::class, 'sale_detail_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:6',
            'taxable_base' => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }
}
