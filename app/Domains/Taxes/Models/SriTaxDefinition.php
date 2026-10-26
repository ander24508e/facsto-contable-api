<?php

namespace App\Domains\Taxes\Models;

use App\Domains\Taxes\Enums\TaxCalculationType;
use App\Domains\Taxes\Enums\TaxType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['tax_type', 'sri_tax_code', 'sri_percentage_code', 'name', 'calculation_type', 'fixed_percentage', 'specific_rate', 'specific_unit', 'percentage_editable', 'calculation_supported', 'enabled_by_default', 'valid_from', 'valid_until', 'legal_basis', 'is_active'])]
class SriTaxDefinition extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $definition) => $definition->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function isCurrentOn(CarbonInterface $date): bool
    {
        return $this->is_active
            && $this->valid_from->lte($date)
            && ($this->valid_until === null || $this->valid_until->gte($date));
    }

    protected function casts(): array
    {
        return [
            'tax_type' => TaxType::class,
            'calculation_type' => TaxCalculationType::class,
            'fixed_percentage' => 'decimal:6',
            'specific_rate' => 'decimal:6',
            'percentage_editable' => 'boolean',
            'calculation_supported' => 'boolean',
            'enabled_by_default' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
