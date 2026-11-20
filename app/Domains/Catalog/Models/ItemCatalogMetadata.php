<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Companies\Models\Company;
use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'item_id', 'brand_id', 'barcode'])]
class ItemCatalogMetadata extends Model
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
