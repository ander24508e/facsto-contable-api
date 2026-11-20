<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A vehicle specification: brand + model + type. A vehicle that uses it takes its type (and price) from it. */
#[Fillable(['company_id', 'vehicle_brand_id', 'vehicle_type_id', 'name', 'is_active'])]
class VehicleModel extends Model
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

    protected $table = 'carwash_vehicle_models';

    public function brand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class, 'vehicle_brand_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'vehicle_model_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
