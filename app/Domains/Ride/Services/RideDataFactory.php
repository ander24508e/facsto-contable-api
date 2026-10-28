<?php

namespace App\Domains\Ride\Services;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Ride\DTOs\RideData;
use Illuminate\Support\Collection;
use LogicException;

/** Builds the RIDE (anexo 2) only from ElectronicDocument snapshots plus the SRI authorization. */
final class RideDataFactory
{
    private const IDENTIFICATION_LABELS = [
        'RUC' => 'RUC',
        'CEDULA' => 'Cédula',
        'PASSPORT' => 'Pasaporte',
        'FINAL_CONSUMER' => 'Consumidor final',
        'FOREIGN_IDENTIFICATION' => 'Identificación del exterior',
    ];

    public function __construct(private readonly Code128Barcode $barcode) {}

    public function make(ElectronicDocument $document): RideData
    {
        if ($document->status !== DocumentStatus::AUTHORIZED || blank($document->authorization_number) || $document->authorized_at === null) {
            throw new LogicException('A RIDE is only generated for an authorized document.');
        }
        $document->loadMissing(['lines', 'taxes', 'paymentMethods']);

        $lines = $document->lines->map(fn ($line): array => [
            'code' => $line->item_code,
            'auxiliaryCode' => $line->auxiliary_code,
            'description' => $line->description,
            'quantity' => $this->trimDecimals((string) $line->quantity),
            'unitPrice' => $this->trimDecimals((string) $line->unit_price, 2),
            'discount' => (string) $line->discount,
            'total' => (string) $line->price_total_without_tax,
        ])->values()->all();

        $ivaGroups = $document->taxes->where('tax_code', '2');
        $totals = [];
        foreach ($ivaGroups->filter(fn ($tax): bool => bccomp((string) $tax->percentage, '0', 6) > 0) as $tax) {
            $totals[] = ['label' => 'SUBTOTAL '.$this->percent((string) $tax->percentage).'%', 'amount' => (string) $tax->taxable_base];
        }
        $totals[] = ['label' => 'SUBTOTAL 0%', 'amount' => (string) $document->subtotal_zero];
        $totals[] = ['label' => 'SUBTOTAL NO OBJETO DE IVA', 'amount' => $this->groupBase($ivaGroups, '6')];
        $totals[] = ['label' => 'SUBTOTAL EXENTO DE IVA', 'amount' => $this->groupBase($ivaGroups, '7')];
        $totals[] = ['label' => 'SUBTOTAL SIN IMPUESTOS', 'amount' => (string) $document->total_without_taxes];
        $totals[] = ['label' => 'TOTAL DESCUENTO', 'amount' => (string) $document->discount_total];
        foreach ($document->taxes->where('tax_code', '3') as $tax) {
            $totals[] = ['label' => 'ICE', 'amount' => (string) $tax->tax_amount];
        }
        foreach ($ivaGroups->filter(fn ($tax): bool => bccomp((string) $tax->percentage, '0', 6) > 0) as $tax) {
            $totals[] = ['label' => 'IVA '.$this->percent((string) $tax->percentage).'%', 'amount' => (string) $tax->tax_amount];
        }
        foreach ($document->taxes->where('tax_code', '5') as $tax) {
            $totals[] = ['label' => 'IRBPNR', 'amount' => (string) $tax->tax_amount];
        }
        $totals[] = ['label' => 'PROPINA', 'amount' => (string) $document->tip_amount];

        $additional = ['RUC Proveedor' => (string) $document->provider_ruc];
        if (filled($document->customer_email)) {
            $additional['Email'] = (string) $document->customer_email;
        }

        return new RideData(
            issuer: [
                'ruc' => $document->issuer_ruc,
                'legalName' => $document->issuer_legal_name,
                'commercialName' => $document->issuer_commercial_name,
                'mainAddress' => $document->issuer_main_address,
                'establishmentAddress' => $document->establishment_address,
                'specialTaxpayerNumber' => $document->special_taxpayer_number,
                'accountingRequired' => $document->accounting_required ? 'SI' : 'NO',
                'withholdingAgentNumber' => $document->withholding_agent_number,
                'artisanQualificationNumber' => $document->artisan_qualification_number,
                'rimpe' => match ($document->rimpe_type) {
                    'ENTREPRENEUR' => 'CONTRIBUYENTE RÉGIMEN RIMPE',
                    'POPULAR_BUSINESS' => 'CONTRIBUYENTE NEGOCIO POPULAR - RÉGIMEN RIMPE',
                    default => null,
                },
            ],
            documentLabel: 'FACTURA',
            fullNumber: $document->full_number,
            accessKey: $document->access_key,
            authorizationNumber: (string) $document->authorization_number,
            authorizedAt: $document->authorized_at->setTimezone('America/Guayaquil')->format('d/m/Y H:i:s'),
            environment: $document->sri_environment === 'PRODUCTION' ? 'PRODUCCIÓN' : 'PRUEBAS',
            emissionType: 'NORMAL',
            issueDate: $document->issue_date->format('d/m/Y'),
            customer: [
                'name' => $document->customer_name,
                'identificationType' => self::IDENTIFICATION_LABELS[$document->customer_identification_type] ?? $document->customer_identification_type,
                'identification' => $document->customer_identification,
                'address' => $document->customer_address,
            ],
            lines: $lines,
            totals: $totals,
            total: (string) $document->total,
            payments: $document->paymentMethods->map(fn ($payment): array => [
                'code' => $payment->method_code,
                'name' => $payment->method_name,
                'amount' => (string) $payment->amount,
                'term' => $payment->term === null ? null : $payment->term.' '.mb_strtolower((string) $payment->time_unit),
            ])->values()->all(),
            additionalInformation: $additional,
            barcodeSvg: $this->barcode->svg($document->access_key, 0.75, 34),
        );
    }

    private function groupBase(Collection $groups, string $percentageCode): string
    {
        return $groups->where('percentage_code', $percentageCode)
            ->reduce(fn (string $sum, $tax): string => bcadd($sum, (string) $tax->taxable_base, 2), '0.00');
    }

    private function percent(string $value): string
    {
        return rtrim(rtrim(bcadd($value, '0', 2), '0'), '.');
    }

    private function trimDecimals(string $value, int $minimum = 0): string
    {
        [$integer, $decimals] = array_pad(explode('.', $value, 2), 2, '');
        $decimals = rtrim($decimals, '0');
        $decimals = str_pad($decimals, $minimum, '0');

        return $decimals === '' ? $integer : $integer.'.'.$decimals;
    }
}
