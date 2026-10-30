<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Companies\Models\Company;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'name', 'description', 'is_active'])]
class Brand extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $brand) => $brand->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function itemMetadata(): HasMany
    {
        return $this->hasMany(ItemCatalogMetadata::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
