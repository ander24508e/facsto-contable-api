<?php

namespace App\Domains\Purchases\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A supplier of the business: its RUC (or cédula), legal name and contact. */
#[Fillable(['company_id', 'identification', 'legal_name', 'email', 'phone', 'address', 'is_active'])]
class Supplier extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $supplier) => $supplier->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
