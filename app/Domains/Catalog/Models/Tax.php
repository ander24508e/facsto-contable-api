<?php

namespace App\Domains\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable(['tax_type', 'sri_code', 'percentage_code', 'name', 'percentage', 'valid_from', 'valid_until', 'is_active'])]
class Tax extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $tax) => $tax->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_taxes')->withTimestamps();
    }

    protected function casts(): array
    {
        return ['percentage' => 'decimal:6', 'valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean'];
    }
}
