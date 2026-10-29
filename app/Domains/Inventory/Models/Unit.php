<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A unit of measure of the company (UND, KG, LT…), chosen from a list so the catalog stays consistent. */
#[Fillable(['company_id', 'name', 'is_active'])]
class Unit extends Model
{
    public const DEFAULTS = ['UND', 'KG', 'LT', 'M', 'CAJA', 'PAQUETE'];

    protected static function booted(): void
    {
        static::creating(fn (self $unit) => $unit->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
