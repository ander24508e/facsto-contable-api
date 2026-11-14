<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'work_order_id', 'kind', 'service_id', 'service_price_id', 'item_id', 'description', 'quantity', 'duration_minutes'])]
class WorkOrderLine extends Model
{
    public const SERVICE = 'SERVICE';

    public const SURCHARGE = 'SURCHARGE';

    protected $table = 'carwash_work_order_lines';

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function servicePrice(): BelongsTo
    {
        return $this->belongsTo(ServicePrice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'duration_minutes' => 'integer'];
    }
}
