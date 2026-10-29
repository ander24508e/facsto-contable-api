<?php

namespace App\Domains\Sales\Models;

use App\Domains\Companies\Models\Company;
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

#[Fillable(['company_id', 'customer_id', 'created_by', 'number', 'status', 'issue_date', 'customer_identification_type', 'customer_identification', 'customer_name', 'customer_address', 'customer_email', 'notes', 'subtotal', 'discount_total', 'tax_total', 'tip_amount', 'total', 'currency', 'api_client_id', 'source_module', 'source_reference', 'idempotency_key'])]
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

    public function details(): HasMany
    {
        return $this->hasMany(SaleDetail::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(SalePaymentMethod::class);
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
