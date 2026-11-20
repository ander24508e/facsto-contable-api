<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Catalog\Models\Item;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Proformas\Models\Proforma;
use App\Domains\Purchases\Models\Purchase;
use App\Models\User;
use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One change of stock (the kardex): signed quantity and the resulting balance. Never edited. SALE and
 * RETURN carry the invoice or credit note that caused them; PURCHASE the purchase of Inversiones; ENTRY and EXIT
 * are by hand with their reason; ADJUSTMENT of a mass count carries its AJ-000001; PROFORMA / PROFORMA_RELEASE the
 * proforma with an advance or on credit that took the units or gave them back.
 */
#[Fillable(['company_id', 'item_id', 'warehouse_id', 'type', 'quantity', 'balance', 'electronic_document_id', 'purchase_id', 'stock_adjustment_id', 'proforma_id', 'reason', 'source_module', 'source_reference', 'user_id', 'note'])]
class StockMovement extends Model
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

    public const INITIAL = 'INITIAL';

    public const ADJUSTMENT = 'ADJUSTMENT';

    public const SALE = 'SALE';

    public const RETURN = 'RETURN';

    public const PURCHASE = 'PURCHASE';

    /** Internal consumption (supplies used, not sold), with the module and reference that caused it. */
    public const CONSUMPTION = 'CONSUMPTION';

    /** By hand, with its reason. */
    public const ENTRY = 'ENTRY';

    public const EXIT = 'EXIT';

    /** A proforma with an advance or on credit takes its products; giving them back (sale, rejection) is a release. */
    public const PROFORMA = 'PROFORMA';

    public const PROFORMA_RELEASE = 'PROFORMA_RELEASE';

    /** A product moved to another warehouse: it leaves the origin (−) and arrives at the destination (+). */
    public const TRANSFER = 'TRANSFER';

    public const REASONS = ['COUNT', 'DAMAGE', 'LOSS', 'DONATION', 'INTERNAL_USE', 'OTHER'];

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(fn (self $movement) => $movement->public_id ??= (string) Str::uuid7());
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function proforma(): BelongsTo
    {
        return $this->belongsTo(Proforma::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'balance' => 'decimal:6'];
    }
}
