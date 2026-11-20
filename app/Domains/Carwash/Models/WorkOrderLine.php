<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Catalog\Models\Item;
use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'work_order_id', 'kind', 'service_id', 'service_price_id', 'item_id', 'description', 'quantity', 'duration_minutes'])]
class WorkOrderLine extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'carwash-orders';
    }

    /** It announces its order. */
    public function livePublicId(): ?string
    {
        return app(Realtime::class)->publicIdOf(WorkOrder::class, $this->work_order_id);
    }

    public function liveAction(string $action): string
    {
        return 'updated';
    }

    /** The Lavadora's own channel: only with the module on and one of its permissions. */
    public function liveModule(): ?string
    {
        return 'carwash';
    }

    public const SERVICE = 'SERVICE';

    public const SURCHARGE = 'SURCHARGE';

    /** An Inventario article (product or service) added when scheduling, at its price, without duration. */
    public const PRODUCT = 'PRODUCT';

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
