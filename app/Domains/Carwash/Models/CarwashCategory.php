<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A catalog category turned on in the Lavadora: its services and products show in Tarifas. */
#[Fillable(['company_id', 'category_id'])]
class CarwashCategory extends Model
{
    protected $table = 'carwash_categories';

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
