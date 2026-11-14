<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Combo de Tarifas: si una orden lleva todos sus servicios, al cobrar llevan `discount_percent` de descuento. */
#[Fillable(['company_id', 'name', 'discount_percent', 'is_active'])]
class Combo extends Model
{
    use HasPublicId;

    protected $table = 'carwash_combos';

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'carwash_combo_services', 'combo_id', 'service_id')->withPivot('company_id');
    }

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
