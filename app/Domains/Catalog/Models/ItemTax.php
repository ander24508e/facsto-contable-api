<?php

namespace App\Domains\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'tax_id'])]
class ItemTax extends Model
{
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
