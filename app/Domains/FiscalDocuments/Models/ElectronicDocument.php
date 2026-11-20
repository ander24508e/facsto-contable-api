<?php

namespace App\Domains\FiscalDocuments\Models;

use App\Domains\Companies\Models\Company;
use App\Domains\Delivery\Models\DocumentDelivery;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sri\Models\SriSubmission;
use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'sale_id', 'modified_document_id', 'reason', 'returns_goods', 'purchase_id', 'fiscal_period', 'related_party', 'support_code', 'support_document_code', 'support_document_number', 'support_document_date', 'support_authorization_number', 'customer_id', 'establishment_id', 'emission_point_id', 'document_type', 'establishment_code', 'emission_point_code', 'sequential', 'full_number', 'numeric_code', 'access_key', 'previous_access_keys', 'issue_date', 'status', 'issuer_ruc', 'issuer_legal_name', 'issuer_commercial_name', 'issuer_main_address', 'establishment_address', 'accounting_required', 'special_taxpayer_number', 'withholding_agent_number', 'artisan_qualification_number', 'rimpe_type', 'sri_environment', 'emission_type', 'xml_version', 'provider_ruc', 'provider_name', 'customer_identification_type', 'customer_identification', 'customer_name', 'customer_address', 'customer_email', 'total_without_taxes', 'subtotal_without_tax', 'subtotal_zero', 'subtotal_taxed', 'discount_total', 'tax_total', 'tip_amount', 'total', 'currency', 'created_by', 'numbered_at', 'xml_built_at', 'signed_at', 'authorization_number', 'authorized_at', 'last_sri_status_at', 'last_error_code'])]
class ElectronicDocument extends Model
{
    use AnnouncesChanges;

    /** Regla 31: invoices, notes and withholdings (Comprobantes, Retenciones). */
    public function liveResource(): string
    {
        return 'documents';
    }

    /**
     * Its state changes in the background (SRI authorization, delivery): it announces itself and the sale or purchase
     * it belongs to, so Ventas and Compras show the new state without F5.
     */
    protected function announceChange(string $action): void
    {
        $realtime = app(Realtime::class);
        $realtime->forCompanyId((int) $this->company_id, 'documents', $this->public_id, $action, $this->updated_at);
        if ($this->sale_id) {
            $realtime->forCompanyId((int) $this->company_id, 'sales', $realtime->publicIdOf(Sale::class, $this->sale_id), 'updated', $this->updated_at);
        }
        if ($this->purchase_id) {
            $realtime->forCompanyId((int) $this->company_id, 'purchases', $realtime->publicIdOf(Purchase::class, $this->purchase_id), 'updated', $this->updated_at);
        }
    }

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

    /** The invoice a credit note modifies. */
    public function modifiedDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'modified_document_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'modified_document_id');
    }

    /** A retention: the purchase of Inversiones it withholds on (its support document). */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function withholdings(): HasMany
    {
        return $this->hasMany(ElectronicDocumentWithholding::class);
    }

    /** Invoices and credit notes only: what Ventas, the dashboard and a customer's summary show. */
    public function scopeSalesDocuments(Builder $query): Builder
    {
        return $query->whereIn('document_type', array_map(fn (DocumentType $type): string => $type->value, DocumentType::SALES));
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
            'returns_goods' => 'boolean',
            'related_party' => 'boolean',
            'support_document_date' => 'date',
            'total_without_taxes' => 'decimal:2',
            'subtotal_without_tax' => 'decimal:2',
            'subtotal_zero' => 'decimal:2',
            'subtotal_taxed' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'tip_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'previous_access_keys' => 'array',
            'numbered_at' => 'immutable_datetime',
            'xml_built_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'last_sri_status_at' => 'immutable_datetime',
        ];
    }
}
