<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Catalog\Models\Item;
use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'item_id', 'warehouse_id', 'quantity'])]
class ItemStock extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'stock';
    }

    /** It announces its item. */
    public function livePublicId(): ?string
    {
        return app(Realtime::class)->publicIdOf(Item::class, $this->item_id);
    }

    public function liveAction(string $action): string
    {
        return 'updated';
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }
}
