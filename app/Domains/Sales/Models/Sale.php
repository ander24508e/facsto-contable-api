<?php

namespace App\Domains\Sales\Models;

use App\Domains\Cash\Models\CashSession;
use App\Domains\Companies\Models\Company;
use App\Domains\Credit\Models\CreditPayment;
use App\Domains\Customers\Models\Customer;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Integrations\Models\ApiClient;
use App\Domains\Sales\Enums\SaleStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'customer_id', 'created_by', 'cash_session_id', 'number', 'status', 'issue_date', 'customer_identification_type', 'customer_identification', 'customer_name', 'customer_address', 'customer_email', 'notes', 'subtotal', 'discount_total', 'tax_total', 'tip_amount', 'total', 'currency', 'api_client_id', 'source_module', 'source_reference', 'idempotency_key'])]
class Sale extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $sale) => $sale->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The open cash register the sale is charged in: it gives the establishment, the point and the warehouse. */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(SaleDetail::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(SalePaymentMethod::class);
    }

    /** Accounts receivable: what a sale on credit still has to collect, by date. */
    public function installments(): HasMany
    {
        return $this->hasMany(SaleInstallment::class)->orderBy('number');
    }

    public function creditPayments(): HasMany
    {
        return $this->hasMany(CreditPayment::class)->latest('id');
    }

    public function electronicDocument(): HasOne
    {
        return $this->hasOne(ElectronicDocument::class);
    }

    /** Display number of the internal sale, e.g. V-00042. */
    public function displayNumber(): string
    {
        return 'V-'.str_pad((string) $this->number, 5, '0', STR_PAD_LEFT);
    }

    /** The module that sent the sale through the API, if any. */
    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'tip_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'issue_date' => 'date',
        ];
    }
}
