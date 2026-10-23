<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Companies\Models\Company;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'type', 'internal_code', 'auxiliary_code', 'name', 'description', 'unit', 'sale_price', 'is_active'])]
class Item extends Model
{
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
        return $this->belongsToMany(Tax::class, 'item_taxes')->withTimestamps();
    }

    protected function casts(): array
    {
        return ['type' => ItemType::class, 'sale_price' => 'decimal:6', 'is_active' => 'boolean'];
    }
}
