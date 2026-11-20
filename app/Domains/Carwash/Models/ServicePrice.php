<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Domains\Catalog\Models\Item;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Price per vehicle: service × specification (brand + model + type). The price and taxes are those of its own
 * Inventario item (managed by the Lavadora), read live; the supplies are what each wash uses up.
 */
#[Fillable(['company_id', 'service_id', 'vehicle_model_id', 'item_id', 'duration_minutes', 'description', 'is_active'])]
class ServicePrice extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'carwash-rates';
    }

    /** The Lavadora's own channel: only with the module on and one of its permissions. */
    public function liveModule(): ?string
    {
        return 'carwash';
    }

    use HasPublicId;

    protected $table = 'carwash_service_prices';

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function supplies(): HasMany
    {
        return $this->hasMany(ServiceSupply::class);
    }

    public function effectiveDuration(): int
    {
        return $this->duration_minutes ?? $this->service->duration_minutes;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'duration_minutes' => 'integer'];
    }
}
