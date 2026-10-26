<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Taxes\Models\CompanyTax;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'item_id', 'company_tax_id'])]
class ItemTax extends Model
{
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(CompanyTax::class, 'company_tax_id');
    }
}
