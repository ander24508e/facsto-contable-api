<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Catalog\Models\Category;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A catalog category turned on in the Lavadora: its services and products show in Tarifas. */
#[Fillable(['company_id', 'category_id'])]
class CarwashCategory extends Model
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

    protected $table = 'carwash_categories';

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
