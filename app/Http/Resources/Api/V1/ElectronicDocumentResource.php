<?php

namespace App\Http\Resources\Api\V1;

use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\Sri\Services\SriTransmissionGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ElectronicDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'salePublicId' => $this->sale?->public_id,
            'documentType' => $this->document_type->value,
            'fullNumber' => $this->full_number,
            'reason' => $this->reason,
            'modifiedDocument' => $this->modified_document_id === null ? null : [
                'publicId' => $this->modifiedDocument?->public_id,
                'fullNumber' => $this->modifiedDocument?->full_number,
                'issueDate' => $this->modifiedDocument?->issue_date?->toDateString(),
            ],
            'sequential' => $this->sequential,
            'accessKey' => $this->access_key,
            'issueDate' => $this->issue_date?->toDateString(),
            'status' => $this->status->value,
            'environment' => $this->sri_environment,
            'authorizationNumber' => $this->authorization_number,
            'authorizedAt' => $this->authorized_at?->toIso8601String(),
            'lastSriStatusAt' => $this->last_sri_status_at?->toIso8601String(),
            'lastErrorCode' => $this->last_error_code,
            // Why a SIGNED document has not left FACSTO yet (global switch, company switch or provider registration).
            'transmissionBlockedReason' => $this->status === DocumentStatus::SIGNED
                ? app(SriTransmissionGate::class)->blockingReason(CompanyFiscalSettings::query()->where('company_id', $this->company_id)->first(), $this->sri_environment)
                : null,
            'timeline' => [
                'numberedAt' => $this->numbered_at?->toIso8601String(),
                'xmlBuiltAt' => $this->xml_built_at?->toIso8601String(),
                'signedAt' => $this->signed_at?->toIso8601String(),
                'authorizedAt' => $this->authorized_at?->toIso8601String(),
            ],
            'customer' => [
                'identificationType' => $this->customer_identification_type,
                'identification' => $this->customer_identification,
                'name' => $this->customer_name,
                'address' => $this->customer_address,
                'email' => $this->customer_email,
            ],
            'totals' => [
                'subtotalWithoutTax' => $this->subtotal_without_tax,
                'subtotalZero' => $this->subtotal_zero,
                'subtotalTaxed' => $this->subtotal_taxed,
                'discountTotal' => $this->discount_total,
                'taxTotal' => $this->tax_total,
                'tipAmount' => $this->tip_amount,
                'totalWithoutTaxes' => $this->total_without_taxes,
                'total' => $this->total,
                'currency' => $this->currency,
            ],
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => [
                'itemCode' => $line->item_code, 'auxiliaryCode' => $line->auxiliary_code, 'description' => $line->description, 'quantity' => $line->quantity,
                'unitPrice' => $line->unit_price, 'discount' => $line->discount, 'subtotal' => $line->subtotal,
                'total' => $line->total, 'taxes' => $line->taxes->map(fn ($tax): array => [
                    'taxCode' => $tax->tax_code, 'percentageCode' => $tax->percentage_code, 'percentage' => $tax->percentage,
                    'taxableBase' => $tax->taxable_base, 'taxAmount' => $tax->tax_amount,
                ])->values(),
            ])->values()),
            'taxes' => $this->whenLoaded('taxes', fn () => $this->taxes->map(fn ($tax): array => [
                'taxCode' => $tax->tax_code, 'percentageCode' => $tax->percentage_code, 'percentage' => $tax->percentage,
                'taxableBase' => $tax->taxable_base, 'taxAmount' => $tax->tax_amount,
            ])->values()),
            'paymentMethods' => $this->whenLoaded('paymentMethods', fn () => $this->paymentMethods->map(fn ($method): array => [
                'methodCode' => $method->method_code, 'methodName' => $method->method_name, 'amount' => $method->amount,
                'term' => $method->term, 'timeUnit' => $method->time_unit, 'reference' => $method->reference,
            ])->values()),
        ];
    }
}
