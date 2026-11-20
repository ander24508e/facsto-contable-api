<?php

namespace App\Domains\Inventory\Models;

use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A unit of measure of the company (UND, KG, LT…), chosen from a list so the catalog stays consistent. */
#[Fillable(['company_id', 'name', 'is_active'])]
class Unit extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'units';
    }

    public const DEFAULTS = ['UND', 'KG', 'LT', 'M', 'CAJA', 'PAQUETE'];

    protected static function booted(): void
    {
        static::creating(fn (self $unit) => $unit->public_id ??= (string) Str::uuid7());
    }

    /** The usual units, created the first time the company needs a unit (inventory, settings or a sale). */
    public static function ensureDefaults(int $companyId): void
    {
        if (! static::query()->where('company_id', $companyId)->exists()) {
            foreach (self::DEFAULTS as $name) {
                static::query()->create(['company_id' => $companyId, 'name' => $name]);
            }
        }
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
