<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'number' => $this->displayNumber(),
            'status' => $this->status->value,
            'issueDate' => $this->issue_date?->toDateString(),
            'customer' => [
                'publicId' => $this->customer?->public_id,
                'identificationType' => $this->customer_identification_type,
                'identification' => $this->customer_identification,
                'name' => $this->customer_name,
                'address' => $this->customer_address,
                'email' => $this->customer_email,
            ],
            'createdBy' => $this->creator?->name,
            'notes' => $this->notes,
            'subtotal' => $this->subtotal,
            'discountTotal' => $this->discount_total,
            'taxTotal' => $this->tax_total,
            'tipAmount' => $this->tip_amount,
            'total' => $this->total,
            'currency' => $this->currency,
            // Summary of the fiscal document born from this sale; the full document lives under /invoices.
            'document' => $this->whenLoaded('electronicDocument', fn () => $this->electronicDocument ? [
                'publicId' => $this->electronicDocument->public_id,
                'fullNumber' => $this->electronicDocument->full_number,
                'accessKey' => $this->electronicDocument->access_key,
                'status' => $this->electronicDocument->status->value,
                'issueDate' => $this->electronicDocument->issue_date?->toDateString(),
                'environment' => $this->electronicDocument->sri_environment,
                'authorizationNumber' => $this->electronicDocument->authorization_number,
                'authorizedAt' => $this->electronicDocument->authorized_at?->toIso8601String(),
                'lastErrorCode' => $this->electronicDocument->last_error_code,
            ] : null),
            'lines' => $this->whenLoaded('details', fn () => $this->details->map(fn ($line): array => [
                'publicId' => $line->public_id,
                'itemPublicId' => $line->item?->public_id,
                'itemCode' => $line->item_code,
                'auxiliaryCode' => $line->auxiliary_code,
                'itemName' => $line->item_name,
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'unitPrice' => $line->unit_price,
                'discountAmount' => $line->discount_amount,
                'subtotal' => $line->subtotal,
                'taxableBase' => $line->taxable_base,
                'taxTotal' => $line->tax_total,
                'total' => $line->total,
                'taxes' => $line->taxes->map(fn ($tax): array => [
                    'taxType' => $tax->tax_type,
                    'sriCode' => $tax->sri_code,
                    'percentageCode' => $tax->percentage_code,
                    'name' => $tax->tax_name,
                    'percentage' => $tax->percentage,
                    'taxableBase' => $tax->taxable_base,
                    'amount' => $tax->tax_amount,
                ])->values(),
            ])->values()),
            'paymentMethods' => $this->whenLoaded('paymentMethods', fn () => $this->paymentMethods->map(fn ($method): array => [
                'fiscalPaymentMethodPublicId' => $method->fiscalPaymentMethod?->public_id,
                'methodCode' => $method->method_code,
                'methodName' => $method->method_name,
                'amount' => $method->amount,
                'reference' => $method->reference,
                'term' => $method->term,
                'timeUnit' => $method->time_unit,
            ])->values()),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
