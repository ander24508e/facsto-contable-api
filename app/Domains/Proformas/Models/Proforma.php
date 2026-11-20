<?php

namespace App\Domains\Proformas\Models;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Customers\Models\Customer;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An offer to a third party, priced like a sale; it never touches cash, sales or the SRI. Stock: while pending or
 * accepted (and not expired) it RESERVES its products; with an advance or on credit it TAKES them (stock_committed)
 * until it becomes a sale or is rejected.
 */
#[Fillable(['company_id', 'number', 'customer_id', 'warehouse_id', 'converted_sale_id', 'created_by', 'customer_identification_type', 'customer_identification', 'customer_name', 'customer_address', 'customer_email', 'customer_phone', 'issue_date', 'expiration_date', 'notes', 'subtotal', 'discount_total', 'tax_total', 'total', 'status', 'rejection_reason', 'converted_at', 'advance_amount', 'advance_payment_method_id', 'on_credit', 'stock_committed'])]
class Proforma extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'proformas';
    }

    public const PENDING = 'PENDING';

    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    public const CONVERTED = 'CONVERTED';

    /** Pending or accepted: still an open offer (it may have expired). */
    public const OPEN = [self::PENDING, self::ACCEPTED];

    protected static function booted(): void
    {
        static::creating(fn (self $proforma) => $proforma->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** Display number, e.g. PRO-000042. */
    public function displayNumber(): string
    {
        return 'PRO-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isExpired(): bool
    {
        return $this->isOpen() && $this->expiration_date !== null && $this->expiration_date->lt(today());
    }

    /** Pays something now (advance) or on credit: its products are taken from stock, not only reserved. */
    public function takesStock(): bool
    {
        return $this->isOpen() && ($this->on_credit || bccomp((string) $this->advance_amount, '0', 2) > 0);
    }

    /** What the list and the detail show: Vencida is an open offer past its date. */
    public function displayStatus(): string
    {
        if ($this->converted_sale_id !== null && $this->status !== self::CONVERTED) {
            return 'CONVERSION_PENDING';
        }

        return $this->isExpired() ? 'EXPIRED' : $this->status;
    }

    public function advancePaymentMethod(): BelongsTo
    {
        return $this->belongsTo(FiscalPaymentMethod::class, 'advance_payment_method_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProformaLine::class)->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiration_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'on_credit' => 'boolean',
            'stock_committed' => 'boolean',
            'converted_at' => 'datetime',
        ];
    }
}
