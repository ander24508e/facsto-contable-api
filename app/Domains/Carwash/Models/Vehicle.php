<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Domains\Customers\Models\Customer;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vehicle, identified by its plate. Each work order carries its own customer (anyone may bring it); `customer` is only
 * the last one who did, suggested at the counter.
 */
#[Fillable(['company_id', 'customer_id', 'plate', 'vehicle_type_id', 'vehicle_brand_id', 'vehicle_model_id', 'color', 'year', 'notes', 'is_active'])]
class Vehicle extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'carwash-vehicles';
    }

    /** The Lavadora's own channel: only with the module on and one of its permissions. */
    public function liveModule(): ?string
    {
        return 'carwash';
    }

    use HasPublicId;

    protected $table = 'carwash_vehicles';

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class, 'vehicle_brand_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'year' => 'integer'];
    }
}
