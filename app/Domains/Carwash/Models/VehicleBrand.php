<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'name', 'is_active'])]
class VehicleBrand extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'carwash-specs';
    }

    /** The Lavadora's own channel: only with the module on and one of its permissions. */
    public function liveModule(): ?string
    {
        return 'carwash';
    }

    use HasPublicId;

    protected $table = 'carwash_vehicle_brands';

    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class, 'vehicle_brand_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
