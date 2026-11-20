<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Work area (Box 1, Túnel de secado): one or two area types, how many vehicles it serves at once and its own
 * hours. A reservation takes the area from its time until the order's services are done.
 */
#[Fillable(['company_id', 'name', 'capacity', 'opens_at', 'closes_at', 'is_active'])]
class WorkArea extends Model
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

    protected $table = 'carwash_work_areas';

    /** Its one or two area types, fixed ones first. */
    public function types(): BelongsToMany
    {
        return $this->belongsToMany(AreaType::class, 'carwash_work_area_type', 'work_area_id', 'area_type_id')->ordered();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'is_active' => 'boolean'];
    }
}
