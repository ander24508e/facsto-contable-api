<?php

namespace App\Domains\FiscalDocuments\Models;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'sale_id', 'customer_id', 'establishment_id', 'emission_point_id', 'document_type', 'establishment_code', 'emission_point_code', 'sequential', 'full_number', 'numeric_code', 'access_key', 'issue_date', 'status', 'customer_identification_type', 'customer_identification', 'customer_name', 'customer_address', 'customer_email', 'subtotal_without_tax', 'subtotal_zero', 'subtotal_taxed', 'discount_total', 'tax_total', 'total', 'currency', 'created_by'])]
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

    protected function casts(): array
    {
        return ['document_type' => DocumentType::class, 'status' => DocumentStatus::class, 'issue_date' => 'date'];
    }
}
