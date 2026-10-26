<?php

namespace App\Domains\FiscalDocuments\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\AccessKeyGenerator;
use App\Domains\FiscalDocuments\Services\DocumentNumberFormatter;
use App\Domains\FiscalDocuments\Services\DocumentSequenceAllocator;
use App\Domains\FiscalDocuments\Services\ElectronicDocumentIssuanceReadiness;
use App\Domains\FiscalDocuments\Services\NumericCodeGenerator;
use App\Domains\FiscalDocuments\Services\SequentialFormatter;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreateInvoiceFromSaleAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ElectronicDocumentIssuanceReadiness $issuance,
        private readonly DocumentSequenceAllocator $sequences,
        private readonly SequentialFormatter $sequentialFormatter,
        private readonly DocumentNumberFormatter $numberFormatter,
        private readonly NumericCodeGenerator $numericCodes,
        private readonly AccessKeyGenerator $accessKeys,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(Sale $sale, User $actor): ElectronicDocument
    {
        $company = $this->context->company();

        try {
            return DB::transaction(function () use ($company, $sale, $actor): ElectronicDocument {
                $sale = $company->sales()->whereKey($sale->id)->lockForUpdate()->with(['customer', 'details.taxes', 'details.item', 'paymentMethods'])->first();
                if (! $sale) {
                    throw new ApiException(ApiErrorCode::SALE_NOT_FOUND, 'La venta no pertenece a la empresa actual.', 404);
                }
                $existing = ElectronicDocument::query()->where('company_id', $company->id)->where('sale_id', $sale->id)->with(['lines.taxes', 'taxes'])->first();
                if ($existing && $sale->status === SaleStatus::DOCUMENT_PREPARED) {
                    return $existing;
                }
                if ($sale->status !== SaleStatus::DRAFT) {
                    throw new ApiException(ApiErrorCode::SALE_NOT_EDITABLE, 'Solo se puede preparar una factura desde un borrador.', 409);
                }
                // No sequential, number or access key exists until the company can sign and continue to the SRI.
                $this->issuance->assertCanIssue($company, $sale);

                $establishment = $company->establishments()->where('is_main', true)->where('is_active', true)->first();
                $point = $establishment?->emissionPoints()->where('is_default', true)->where('is_active', true)->first();
                $settings = $company->fiscalSettings;
                if (! $establishment || ! $point || ! $settings?->sri_environment || ! $settings?->emission_type) {
                    throw new ApiException(ApiErrorCode::FISCAL_SETUP_INCOMPLETE, 'La estructura fiscal de emisión no está lista.', 409);
                }

                $providerRuc = (string) config('sri.software_provider.ruc');

                $type = DocumentType::INVOICE;
                $number = $this->sequences->next($company->id, $establishment->id, $point->id, $type);
                $sequential = $this->sequentialFormatter->format($number);
                $fullNumber = $this->numberFormatter->format($establishment->code, $point->code, $sequential);
                $numericCode = $this->numericCodes->generate();
                $issuedAt = now();
                $issueDate = $issuedAt->toDateString();
                $accessKey = $this->accessKeys->generate($issuedAt, $type, $company->ruc, $settings->sri_environment, $establishment->code, $point->code, $sequential, $numericCode, $settings->emission_type);

                $taxGroups = [];
                $totalWithoutTaxes = '0.00';
                $subtotalWithoutTax = '0.00';
                $subtotalZero = '0.00';
                $subtotalTaxed = '0.00';
                foreach ($sale->details as $line) {
                    $totalWithoutTaxes = bcadd($totalWithoutTaxes, (string) $line->taxable_base, 2);
                    $iva = $line->taxes->firstWhere('tax_type', 'IVA');
                    // Tabla 17: 6 = no objeto de IVA, 7 = exento; both are reported apart from the 0 % rate.
                    if (in_array($iva?->percentage_code, ['6', '7'], true)) {
                        $subtotalWithoutTax = bcadd($subtotalWithoutTax, $line->taxable_base, 2);
                    } elseif (bccomp((string) $iva?->percentage, '0', 6) === 0) {
                        $subtotalZero = bcadd($subtotalZero, $line->taxable_base, 2);
                    } else {
                        $subtotalTaxed = bcadd($subtotalTaxed, $line->taxable_base, 2);
                    }
                    foreach ($line->taxes as $tax) {
                        $key = $tax->sri_code.'|'.$tax->percentage_code;
                        $taxGroups[$key] ??= ['tax_code' => $tax->sri_code, 'percentage_code' => $tax->percentage_code, 'percentage' => $tax->percentage, 'taxable_base' => '0.00', 'tax_amount' => '0.00'];
                        $taxGroups[$key]['taxable_base'] = bcadd($taxGroups[$key]['taxable_base'], $tax->taxable_base, 2);
                        $taxGroups[$key]['tax_amount'] = bcadd($taxGroups[$key]['tax_amount'], $tax->tax_amount, 2);
                    }
                }

                // <totalSinImpuestos> is the whole document before taxes; it must match the sale header exactly.
                if (bccomp($totalWithoutTaxes, bcsub((string) $sale->subtotal, (string) $sale->discount_total, 2), 2) !== 0) {
                    throw new RuntimeException('INVOICE_TOTAL_WITHOUT_TAXES_MISMATCH');
                }

                $document = ElectronicDocument::query()->create([
                    'company_id' => $company->id, 'sale_id' => $sale->id, 'customer_id' => $sale->customer_id,
                    'establishment_id' => $establishment->id, 'emission_point_id' => $point->id, 'document_type' => $type,
                    'establishment_code' => $establishment->code, 'emission_point_code' => $point->code,
                    'sequential' => $sequential, 'full_number' => $fullNumber, 'numeric_code' => $numericCode,
                    'access_key' => $accessKey, 'issue_date' => $issueDate, 'status' => DocumentStatus::NUMBERED,
                    'issuer_ruc' => $company->ruc,
                    'issuer_legal_name' => $company->legal_name,
                    'issuer_commercial_name' => $company->commercial_name,
                    'issuer_main_address' => $company->main_address,
                    'establishment_address' => $establishment->address,
                    'accounting_required' => $settings->accounting_required,
                    'special_taxpayer_number' => $settings->special_taxpayer_number,
                    'withholding_agent_number' => $settings->withholding_agent_number,
                    'rimpe_type' => $settings->rimpe_type?->value,
                    'sri_environment' => $settings->sri_environment->value,
                    'emission_type' => $settings->emission_type->value,
                    'xml_version' => config('sri.invoice.xml_version', '1.1.0'),
                    'provider_ruc' => $providerRuc,
                    'provider_name' => config('sri.software_provider.name', 'Condore Software'),
                    'customer_identification_type' => $sale->customer_identification_type, 'customer_identification' => $sale->customer_identification,
                    'customer_name' => $sale->customer_name, 'customer_address' => $sale->customer_address, 'customer_email' => $sale->customer_email,
                    'total_without_taxes' => $totalWithoutTaxes, 'subtotal_without_tax' => $subtotalWithoutTax, 'subtotal_zero' => $subtotalZero, 'subtotal_taxed' => $subtotalTaxed,
                    'discount_total' => $sale->discount_total, 'tax_total' => $sale->tax_total, 'tip_amount' => $sale->tip_amount, 'total' => $sale->total,
                    'currency' => $sale->currency, 'created_by' => $actor->id,
                ]);
                foreach ($sale->details as $line) {
                    $documentLine = $document->lines()->create([
                        'company_id' => $company->id, 'item_code' => $line->item_code, 'auxiliary_code' => $line->auxiliary_code, 'description' => $line->item_name,
                        'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'discount' => $line->discount_amount,
                        'subtotal' => $line->subtotal, 'price_total_without_tax' => $line->taxable_base, 'total' => $line->total,
                    ]);
                    $documentLine->taxes()->createMany($line->taxes->map(fn ($tax): array => [
                        'company_id' => $company->id, 'tax_code' => $tax->sri_code, 'percentage_code' => $tax->percentage_code,
                        'percentage' => $tax->percentage, 'taxable_base' => $tax->taxable_base, 'tax_amount' => $tax->tax_amount,
                    ])->all());
                }
                $document->taxes()->createMany(array_map(fn (array $tax): array => ['company_id' => $company->id, ...$tax], array_values($taxGroups)));
                $document->paymentMethods()->createMany($sale->paymentMethods->map(fn ($method): array => [
                    'company_id' => $company->id, 'method_code' => $method->method_code, 'method_name' => $method->method_name,
                    'amount' => $method->amount, 'term' => $method->term, 'time_unit' => $method->time_unit, 'reference' => $method->reference,
                ])->all());
                $sale->forceFill(['status' => SaleStatus::DOCUMENT_PREPARED, 'issue_date' => $issueDate])->save();
                $this->audit->record($actor, $company, 'invoice.numbered', $document, null, ['fullNumber' => $fullNumber, 'status' => DocumentStatus::NUMBERED->value]);

                return $document->load(['lines.taxes', 'taxes', 'paymentMethods']);
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'DOCUMENT_SEQUENCE_EXHAUSTED') {
                throw new ApiException(ApiErrorCode::DOCUMENT_SEQUENCE_EXHAUSTED, 'La secuencia de este punto de emisión se agotó.', 409);
            }
            throw $exception;
        }
    }
}
