<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Domains\Catalog\Models\Category;
use App\Domains\Taxes\Models\CompanyTax;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A global washing service (Lavada Express). Its category and IVA go to the Inventario items of its prices per
 * vehicle, which are what gets invoiced.
 */
#[Fillable(['company_id', 'name', 'description', 'category_id', 'company_tax_id', 'duration_minutes', 'is_featured', 'is_bookable', 'is_active'])]
class Service extends Model
{
    use HasPublicId;

    protected $table = 'carwash_services';

    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(CompanyTax::class, 'company_tax_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_featured' => 'boolean', 'is_bookable' => 'boolean', 'duration_minutes' => 'integer'];
    }
}
