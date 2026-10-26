<?php

namespace App\Domains\Taxes\Models;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/** Una tarifa oficial que la empresa aplica; código y porcentaje siempre vienen de la definición. */
#[Fillable(['company_id', 'sri_tax_definition_id', 'name', 'valid_from', 'valid_until', 'is_active'])]
class CompanyTax extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $tax) => $tax->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(SriTaxDefinition::class, 'sri_tax_definition_id');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_taxes')->withPivot('company_id')->withTimestamps();
    }

    /** Usable in a sale: active here, officially current and with a calculation FACSTO implements. */
    public function isUsableOn(CarbonInterface $date): bool
    {
        $definition = $this->definition;

        return $this->is_active
            && $definition->calculation_supported
            && $definition->isCurrentOn($date)
            && ($this->valid_from === null || $this->valid_from->lte($date))
            && ($this->valid_until === null || $this->valid_until->gte($date));
    }

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'is_active' => 'boolean'];
    }
}
