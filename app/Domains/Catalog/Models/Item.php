<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Companies\Models\Company;
use App\Domains\Inventory\Models\ItemPriceTier;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Taxes\Models\CompanyTax;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'category_id', 'type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'price_includes_tax', 'cost_price', 'additional_detail', 'is_active'])]
class Item extends Model
{
    /** Units at or below which a product shows in the dashboard «stock bajo» alert. */
    public const LOW_STOCK = 5;

    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(CompanyTax::class, 'item_taxes')->withPivot('company_id')->withTimestamps();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ItemStock::class);
    }

    /** Wholesale prices, the smallest quantity first. */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ItemPriceTier::class)->orderBy('min_quantity');
    }

    protected function casts(): array
    {
        return ['type' => ItemType::class, 'sale_price' => 'decimal:6', 'cost_price' => 'decimal:6', 'price_includes_tax' => 'boolean', 'is_active' => 'boolean'];
    }
}
