<?php

namespace App\Domains\Purchases\Models;

use App\Domains\Companies\Models\Company;
use App\Domains\Credit\Models\CreditPayment;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Inventory\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/** A purchase of Inversiones: its own numbering (COM-000001), it adds stock and may be paid in installments. */
#[Fillable(['company_id', 'number', 'supplier_id', 'warehouse_id', 'created_by', 'issue_date', 'supplier_document_number', 'supplier_authorization_number', 'fiscal_payment_method_id', 'payment_method_code', 'payment_method_name', 'is_credit', 'down_payment', 'installment_frequency', 'credit_notes', 'subtotal', 'tax_total', 'total', 'notes'])]
class Purchase extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $purchase) => $purchase->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CreditPayment::class)->latest('id');
    }

    public function displayNumber(): string
    {
        return 'COM-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    /** Accounts payable: what a purchase on credit still has to pay, by date. */
    public function installments(): HasMany
    {
        return $this->hasMany(PurchaseInstallment::class)->orderBy('number');
    }

    /** Its comprobante de retención (07), when the company withholds. */
    public function retention(): HasOne
    {
        return $this->hasOne(ElectronicDocument::class);
    }

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'is_credit' => 'boolean', 'subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2'];
    }
}
