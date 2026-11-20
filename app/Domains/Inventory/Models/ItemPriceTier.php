<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Catalog\Models\Item;
use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Alternate (wholesale) price: from `min_quantity` units on, each unit costs `price`. */
#[Fillable(['company_id', 'item_id', 'min_quantity', 'price'])]
class ItemPriceTier extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'items';
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

    protected function casts(): array
    {
        return ['min_quantity' => 'decimal:6', 'price' => 'decimal:6'];
    }
}
