<?php

namespace App\Domains\FiscalDocuments\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Models\ElectronicDocumentLine;
use App\Domains\FiscalDocuments\Services\AccessKeyGenerator;
use App\Domains\FiscalDocuments\Services\DocumentNumberFormatter;
use App\Domains\FiscalDocuments\Services\DocumentSequenceAllocator;
use App\Domains\FiscalDocuments\Services\ElectronicDocumentIssuanceReadiness;
use App\Domains\FiscalDocuments\Services\NumericCodeGenerator;
use App\Domains\FiscalDocuments\Services\SequentialFormatter;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * POST /invoices/{invoice}/credit-notes — total or partial return of an authorized invoice (codDoc 04).
 * Each line returns a quantity of an invoice line, never more than what is still unreturned; amounts are
 * proportional to the invoice line, so the credit note never exceeds what was invoiced. It is numbered
 * on the invoice's emission point and then follows the same XML → signature → SRI pipeline.
 */
final class CreateCreditNoteAction
{
    /** Credit notes that do not consume the invoice's returnable quantities. */
    private const VOID_STATUSES = [DocumentStatus::NOT_AUTHORIZED];

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

    /** @param list<array{lineNumber: int, quantity: string}> $lines 1-based positions of the invoice lines */
    public function execute(ElectronicDocument $invoice, string $reason, array $lines, User $actor): ElectronicDocument
    {
        $company = $this->context->company();
        $pending = $this->issuance->companyPending($company);
        if ($pending !== []) {
            throw new ApiException(ApiErrorCode::FISCAL_SETUP_INCOMPLETE, 'La empresa no puede emitir comprobantes en este momento.', 409, ['pending' => $pending]);
        }

        try {
            return DB::transaction(function () use ($company, $invoice, $reason, $lines, $actor): ElectronicDocument {
                $invoice = ElectronicDocument::query()->where('company_id', $company->id)->whereKey($invoice->id)->lockForUpdate()->with(['lines.taxes'])->firstOrFail();
                if ($invoice->document_type !== DocumentType::INVOICE || $invoice->status !== DocumentStatus::AUTHORIZED) {
                    throw new ApiException(ApiErrorCode::INVALID_DOCUMENT_TRANSITION, 'Sólo se puede emitir una nota de crédito sobre una factura autorizada por el SRI.', 409);
                }
                if ($invoice->customer_identification_type === IdentificationType::FINAL_CONSUMER->value) {
                    throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El SRI no admite notas de crédito a consumidor final.', 422);
                }
                if ($invoice->issuer_ruc !== $company->ruc) {
                    throw new ApiException(ApiErrorCode::INVALID_DOCUMENT_TRANSITION, 'La factura pertenece a otro RUC del emisor.', 409);
                }

                $returned = $this->returnedQuantities($invoice);
                $invoiceLines = $invoice->lines->sortBy('id')->values();
                $creditLines = [];
                foreach ($lines as $requested) {
                    $source = $invoiceLines->get(((int) $requested['lineNumber']) - 1);
                    if (! $source instanceof ElectronicDocumentLine) {
                        throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Una de las líneas no existe en la factura.', 422);
                    }
                    $quantity = bcadd((string) $requested['quantity'], '0', 6);
                    $available = bcsub((string) $source->quantity, $returned[$source->id] ?? '0', 6);
                    if (bccomp($quantity, '0', 6) <= 0 || bccomp($quantity, $available, 6) > 0) {
                        throw new ApiException(ApiErrorCode::VALIDATION_FAILED, "La cantidad a devolver de «{$source->description}» supera la que queda por devolver ({$this->trim($available)}).", 422, [
                            'lineNumber' => (int) $requested['lineNumber'], 'available' => $available,
                        ]);
                    }
                    $creditLines[] = $this->proportionalLine($source, $quantity);
                }

                $type = DocumentType::CREDIT_NOTE;
                $settings = $company->fiscalSettings;
                $number = $this->sequences->next($company->id, $invoice->establishment_id, $invoice->emission_point_id, $type, (string) $company->ruc);
                $sequential = $this->sequentialFormatter->format($number);
                $numericCode = $this->numericCodes->generate();
                $issuedAt = now();
                $accessKey = $this->accessKeys->generate($issuedAt, $type, $company->ruc, $settings->sri_environment, $invoice->establishment_code, $invoice->emission_point_code, $sequential, $numericCode, $settings->emission_type);
                $totals = $this->totals($creditLines);

                $creditNote = ElectronicDocument::query()->create([
                    ...$invoice->only([
                        'company_id', 'customer_id', 'establishment_id', 'emission_point_id', 'establishment_code', 'emission_point_code',
                        'issuer_ruc', 'issuer_legal_name', 'issuer_commercial_name', 'issuer_main_address', 'establishment_address',
                        'accounting_required', 'special_taxpayer_number', 'withholding_agent_number', 'artisan_qualification_number', 'rimpe_type',
                        'provider_ruc', 'provider_name', 'customer_identification_type', 'customer_identification', 'customer_name',
                        'customer_address', 'customer_email', 'currency',
                    ]),
                    'sale_id' => null,
                    'modified_document_id' => $invoice->id,
                    'reason' => $reason,
                    'document_type' => $type,
                    'sequential' => $sequential,
                    'full_number' => $this->numberFormatter->format($invoice->establishment_code, $invoice->emission_point_code, $sequential),
                    'numeric_code' => $numericCode,
                    'access_key' => $accessKey,
                    'issue_date' => $issuedAt->toDateString(),
                    'status' => DocumentStatus::NUMBERED,
                    'sri_environment' => $settings->sri_environment->value,
                    'emission_type' => $settings->emission_type->value,
                    'xml_version' => config('sri.credit_note.xml_version', '1.1.0'),
                    'total_without_taxes' => $totals['base'],
                    'subtotal_without_tax' => $totals['without_tax'],
                    'subtotal_zero' => $totals['zero'],
                    'subtotal_taxed' => $totals['taxed'],
                    'discount_total' => $totals['discount'],
                    'tax_total' => $totals['tax'],
                    'tip_amount' => '0.00',
                    'total' => bcadd($totals['base'], $totals['tax'], 2),
                    'created_by' => $actor->id,
                    'numbered_at' => $issuedAt,
                ]);
                foreach ($creditLines as $line) {
                    $created = $creditNote->lines()->create([...$line['line'], 'company_id' => $company->id]);
                    $created->taxes()->createMany(array_map(fn (array $tax): array => [...$tax, 'company_id' => $company->id], $line['taxes']));
                }
                $creditNote->taxes()->createMany(array_map(fn (array $tax): array => [...$tax, 'company_id' => $company->id], $totals['groups']));
                $this->audit->record($actor, $company, 'credit_note.numbered', $creditNote, null, [
                    'fullNumber' => $creditNote->full_number, 'modifiedDocument' => $invoice->full_number, 'total' => $creditNote->total, 'reason' => $reason,
                ]);

                return $creditNote->load(['lines.taxes', 'taxes', 'modifiedDocument']);
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'DOCUMENT_SEQUENCE_EXHAUSTED') {
                throw new ApiException(ApiErrorCode::DOCUMENT_SEQUENCE_EXHAUSTED, 'La secuencia de notas de crédito de este punto de emisión se agotó.', 409);
            }
            throw $exception;
        }
    }

    /** @return array<int, string> quantity already returned per invoice line id */
    private function returnedQuantities(ElectronicDocument $invoice): array
    {
        return ElectronicDocumentLine::query()
            ->whereNotNull('source_line_id')
            ->whereIn('electronic_document_id', ElectronicDocument::query()
                ->where('modified_document_id', $invoice->id)
                ->whereNotIn('status', array_map(fn (DocumentStatus $status): string => $status->value, self::VOID_STATUSES))
                ->select('id'))
            ->get(['source_line_id', 'quantity'])
            ->groupBy('source_line_id')
            ->map(fn ($rows): string => $rows->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->quantity, 6), '0'))
            ->all();
    }

    /** The returned share of an invoice line: discount and taxes in the same proportion as the quantity. */
    private function proportionalLine(ElectronicDocumentLine $source, string $quantity): array
    {
        $whole = bccomp($quantity, (string) $source->quantity, 6) === 0;
        $subtotal = $whole ? (string) $source->subtotal : $this->round(bcmul($quantity, (string) $source->unit_price, 8));
        $discount = $whole ? (string) $source->discount : $this->round(bcdiv(bcmul((string) $source->discount, $quantity, 10), (string) $source->quantity, 10));
        $base = bcsub($subtotal, $discount, 2);
        $taxes = [];
        $taxTotal = '0.00';
        foreach ($source->taxes as $tax) {
            $amount = $whole ? (string) $tax->tax_amount : $this->round(bcdiv(bcmul($base, (string) $tax->percentage, 10), '100', 10));
            $taxTotal = bcadd($taxTotal, $amount, 2);
            $taxes[] = [
                'tax_code' => $tax->tax_code, 'percentage_code' => $tax->percentage_code, 'percentage' => $tax->percentage,
                'taxable_base' => $base, 'tax_amount' => $amount,
            ];
        }

        return [
            'line' => [
                'source_line_id' => $source->id, 'item_code' => $source->item_code, 'auxiliary_code' => $source->auxiliary_code,
                'description' => $source->description, 'quantity' => $quantity, 'unit_price' => $source->unit_price, 'discount' => $discount,
                'subtotal' => $subtotal, 'price_total_without_tax' => $base, 'total' => bcadd($base, $taxTotal, 2),
            ],
            'taxes' => $taxes,
        ];
    }

    /** @param list<array{line: array<string, mixed>, taxes: list<array<string, mixed>>}> $lines */
    private function totals(array $lines): array
    {
        $totals = ['base' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'without_tax' => '0.00', 'zero' => '0.00', 'taxed' => '0.00', 'groups' => []];
        foreach ($lines as $line) {
            $base = (string) $line['line']['price_total_without_tax'];
            $totals['base'] = bcadd($totals['base'], $base, 2);
            $totals['discount'] = bcadd($totals['discount'], (string) $line['line']['discount'], 2);
            $iva = collect($line['taxes'])->firstWhere('tax_code', '2');
            $bucket = match (true) {
                in_array($iva['percentage_code'] ?? null, ['6', '7'], true) => 'without_tax',
                bccomp((string) ($iva['percentage'] ?? '0'), '0', 6) === 0 => 'zero',
                default => 'taxed',
            };
            $totals[$bucket] = bcadd($totals[$bucket], $base, 2);
            foreach ($line['taxes'] as $tax) {
                $key = $tax['tax_code'].'|'.$tax['percentage_code'];
                $totals['groups'][$key] ??= ['tax_code' => $tax['tax_code'], 'percentage_code' => $tax['percentage_code'], 'percentage' => $tax['percentage'], 'taxable_base' => '0.00', 'tax_amount' => '0.00'];
                $totals['groups'][$key]['taxable_base'] = bcadd($totals['groups'][$key]['taxable_base'], $tax['taxable_base'], 2);
                $totals['groups'][$key]['tax_amount'] = bcadd($totals['groups'][$key]['tax_amount'], $tax['tax_amount'], 2);
                $totals['tax'] = bcadd($totals['tax'], $tax['tax_amount'], 2);
            }
        }
        $totals['groups'] = array_values($totals['groups']);

        return $totals;
    }

    private function round(string $value): string
    {
        return bcadd($value, bccomp($value, '0', 10) >= 0 ? '0.005' : '-0.005', 2);
    }

    private function trim(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
