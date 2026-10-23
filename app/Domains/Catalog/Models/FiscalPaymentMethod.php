<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Sales\Models\SalePaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['code', 'name', 'valid_from', 'valid_until', 'is_active'])]
class FiscalPaymentMethod extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $method) => $method->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function salePaymentMethods(): HasMany
    {
        return $this->hasMany(SalePaymentMethod::class);
    }

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean'];
    }
}
