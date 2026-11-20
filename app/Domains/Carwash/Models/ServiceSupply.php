<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Catalog\Models\Item;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supply of a price per vehicle: the Inventario product and how much one wash uses (in `measure`, or its unit). */
#[Fillable(['company_id', 'service_price_id', 'item_id', 'quantity', 'measure'])]
class ServiceSupply extends Model
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

    protected $table = 'carwash_service_supplies';

    public function price(): BelongsTo
    {
        return $this->belongsTo(ServicePrice::class, 'service_price_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }
}
