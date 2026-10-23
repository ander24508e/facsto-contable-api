<?php

namespace App\Domains\Companies\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'code', 'name', 'address', 'is_active', 'is_main'])]
class Establishment extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $establishment) => $establishment->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function emissionPoints(): HasMany
    {
        return $this->hasMany(EmissionPoint::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_main' => 'boolean'];
    }
}
