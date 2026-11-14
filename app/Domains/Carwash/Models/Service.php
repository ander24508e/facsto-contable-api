<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Domains\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An entry of Tarifas: an Inventario item (service or product) of a category turned on in the Lavadora. Its name,
 * category, IVA, photo and status are the item's; the Lavadora keeps only the base duration, the area type it needs and, for a service,
 * its prices per vehicle (each one its own Inventario item, which is what gets invoiced).
 */
#[Fillable(['company_id', 'item_id', 'duration_minutes', 'area_type_id', 'commission_type', 'commission_value'])]
class Service extends Model
{
    use HasPublicId;

    protected $table = 'carwash_services';

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** The area type it needs; null: any area. */
    public function areaType(): BelongsTo
    {
        return $this->belongsTo(AreaType::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    /** For a product: the prices per vehicle that use it as a supply. */
    public function usages(): HasMany
    {
        return $this->hasMany(ServiceSupply::class, 'item_id', 'item_id');
    }

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer', 'commission_value' => 'decimal:2'];
    }
}
