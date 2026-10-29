<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Companies\Models\Establishment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** The single warehouse of an establishment, where its products are stored. */
#[Fillable(['company_id', 'establishment_id', 'name', 'is_active'])]
class Warehouse extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $warehouse) => $warehouse->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ItemStock::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
