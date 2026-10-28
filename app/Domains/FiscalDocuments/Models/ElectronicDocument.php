<?php

namespace App\Domains\FiscalDocuments\Models;

use App\Domains\Companies\Models\Company;
use App\Domains\Delivery\Models\DocumentDelivery;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sri\Models\SriSubmission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'sale_id', 'customer_id', 'establishment_id', 'emission_point_id', 'document_type', 'establishment_code', 'emission_point_code', 'sequential', 'full_number', 'numeric_code', 'access_key', 'issue_date', 'status', 'issuer_ruc', 'issuer_legal_name', 'issuer_commercial_name', 'issuer_main_address', 'establishment_address', 'accounting_required', 'special_taxpayer_number', 'withholding_agent_number', 'artisan_qualification_number', 'rimpe_type', 'sri_environment', 'emission_type', 'xml_version', 'provider_ruc', 'provider_name', 'customer_identification_type', 'customer_identification', 'customer_name', 'customer_address', 'customer_email', 'total_without_taxes', 'subtotal_without_tax', 'subtotal_zero', 'subtotal_taxed', 'discount_total', 'tax_total', 'tip_amount', 'total', 'currency', 'created_by', 'numbered_at', 'xml_built_at', 'signed_at', 'authorization_number', 'authorized_at', 'last_sri_status_at', 'last_error_code'])]
class ElectronicDocument extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $document) => $document->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** Automatic SRI submission retries stop at 23:59:59 (Ecuador) of the issue date. */
    public function sriRetryDeadline(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->issue_date->format('Y-m-d').' 23:59:59', (string) config('sri.timezone', 'America/Guayaquil'));
    }

    public function sriRetryWindowOpen(): bool
    {
        return now()->lessThanOrEqualTo($this->sriRetryDeadline());
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SriSubmission::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(DocumentDelivery::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ElectronicDocumentLine::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(ElectronicDocumentTax::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(ElectronicDocumentPaymentMethod::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(DocumentArtifact::class);
    }

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'issue_date' => 'date',
            'accounting_required' => 'boolean',
            'total_without_taxes' => 'decimal:2',
            'subtotal_without_tax' => 'decimal:2',
            'subtotal_zero' => 'decimal:2',
            'subtotal_taxed' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'tip_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'numbered_at' => 'immutable_datetime',
            'xml_built_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'last_sri_status_at' => 'immutable_datetime',
        ];
    }
}
