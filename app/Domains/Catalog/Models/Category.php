<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Companies\Models\Company;
use App\Domains\Taxes\Models\CompanyTax;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'name', 'description', 'is_active'])]
class Category extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $category) => $category->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Default rates inherited by new items of the category. */
    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(CompanyTax::class, 'category_taxes')->withPivot('company_id')->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
