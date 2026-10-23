<?php

namespace App\Domains\Companies\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'establishment_id', 'code', 'name', 'is_active', 'is_default'])]
class EmissionPoint extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $point) => $point->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean'];
    }
}
