<?php

namespace App\Domains\Proformas\Models;

use App\Domains\Customers\Models\Customer;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** An offer to a third party: priced like a sale, but it never touches stock, cash, sales or the SRI. */
#[Fillable(['company_id', 'number', 'customer_id', 'warehouse_id', 'converted_sale_id', 'created_by', 'customer_identification_type', 'customer_identification', 'customer_name', 'customer_address', 'customer_email', 'customer_phone', 'issue_date', 'expiration_date', 'notes', 'subtotal', 'discount_total', 'tax_total', 'total'])]
class Proforma extends Model
{
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
        ];
    }
}
