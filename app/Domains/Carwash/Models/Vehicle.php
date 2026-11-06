<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Domains\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A vehicle of a FACSTO customer (the customers table itself, never a copy). */
#[Fillable(['company_id', 'customer_id', 'plate', 'vehicle_type_id', 'vehicle_brand_id', 'vehicle_model_id', 'color', 'year', 'notes', 'is_active'])]
class Vehicle extends Model
{
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
