<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tipo de área (Lavado, Pulido, Tapicería…). Lavado, Secado y Lubricación vienen fijos (`code`): se crean solos por
 * empresa (AreaTypeCatalog) y no se renombran ni se desactivan; los demás los crea la empresa.
 */
#[Fillable(['company_id', 'code', 'name', 'is_active'])]
class AreaType extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'carwash-areas';
    }

    /** The Lavadora's own channel: only with the module on and one of its permissions. */
    public function liveModule(): ?string
    {
        return 'carwash';
    }

    use HasPublicId;

    /** The fixed types, in the order they are shown. */
    public const FIXED = ['WASH' => 'Lavado', 'DRY' => 'Secado', 'LUBE' => 'Lubricación'];

    /** Fixed ones first (Lavado, Secado, Lubricación), then the company's by name. */
    public const ORDER = "CASE carwash_area_types.code WHEN 'WASH' THEN 1 WHEN 'DRY' THEN 2 WHEN 'LUBE' THEN 3 ELSE 4 END";

    protected $table = 'carwash_area_types';

    public function isFixed(): bool
    {
        return $this->code !== null;
    }

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(WorkArea::class, 'carwash_work_area_type', 'area_type_id', 'work_area_id');
    }

    /** @param Builder<AreaType> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw(self::ORDER)->orderBy('carwash_area_types.name');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
