<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Sales\Models\SalePaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A payment method: the SRI's table 24 plus CREDITO (is_credit), which sends code 01 with its term and
 * is collected in installments.
 */
#[Fillable(['code', 'name', 'valid_from', 'valid_until', 'is_credit', 'is_active'])]
class FiscalPaymentMethod extends Model
{
    public const CREDIT_NAME = 'CREDITO';

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

    /** Active methods valid today. */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereDate('valid_from', '<=', today())
            ->where(fn (Builder $valid) => $valid->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
    }

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'is_credit' => 'boolean', 'is_active' => 'boolean'];
    }
}
