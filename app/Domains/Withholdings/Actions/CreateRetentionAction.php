<?php

namespace App\Domains\Withholdings\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\EmissionPoint;
use App\Domains\Credit\Services\CreditPaymentRecorder;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\AccessKeyGenerator;
use App\Domains\FiscalDocuments\Services\DocumentNumberFormatter;
use App\Domains\FiscalDocuments\Services\DocumentSequenceAllocator;
use App\Domains\FiscalDocuments\Services\ElectronicDocumentIssuanceReadiness;
use App\Domains\FiscalDocuments\Services\NumericCodeGenerator;
use App\Domains\FiscalDocuments\Services\SequentialFormatter;
use App\Domains\Purchases\Actions\EnsureSupplierCustomerAction;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Withholdings\Models\SriWithholdingCode;
use App\Domains\Withholdings\Services\WithholdingAgentStatus;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * POST /purchases/{purchase}/retention — comprobante de retención (codDoc 07) on a purchase of Inversiones.
 * Only a withholding agent (by its fiscal profile) issues it. The purchase is its support document: the
 * supplier's invoice number, date, totals, IVA rates and payment are frozen on the retention. Renta is
 * withheld on the subtotal and IVA on the IVA of the purchase. On credit, what is withheld lowers what is
 * owed to the supplier (cuentas por pagar). One retention per purchase; a rejected one is retried with
 * the same number.
 */
final class CreateRetentionAction
{
    public const SUPPORT_CODES = ['00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10'];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly WithholdingAgentStatus $agent,
        private readonly ElectronicDocumentIssuanceReadiness $issuance,
        private readonly EnsureSupplierCustomerAction $supplierCustomer,
        private readonly DocumentSequenceAllocator $sequences,
        private readonly SequentialFormatter $sequentialFormatter,
        private readonly DocumentNumberFormatter $numberFormatter,
        private readonly NumericCodeGenerator $numericCodes,
        private readonly AccessKeyGenerator $accessKeys,
        private readonly CreditPaymentRecorder $payables,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{emissionPointPublicId: string, supportCode: string, relatedParty?: bool, withholdings: list<array{codePublicId: string, taxableBase: string, percentage?: ?string}>}  $data
     */
    public function execute(Purchase $purchase, array $data, User $actor): ElectronicDocument
    {
        $company = $this->context->company();
        if (! $this->agent->canWithhold($company)) {
            throw new ApiException(ApiErrorCode::WITHHOLDING_AGENT_REQUIRED, 'Según su perfil fiscal, la empresa no es agente de retención.', 409);
        }
        $pending = $this->issuance->companyPending($company);
        if ($pending !== []) {
            throw new ApiException(ApiErrorCode::FISCAL_SETUP_INCOMPLETE, 'La empresa no puede emitir comprobantes en este momento.', 409, ['pending' => $pending]);
        }

        try {
            return DB::transaction(function () use ($company, $purchase, $data, $actor): ElectronicDocument {
                $purchase = Purchase::query()->where('company_id', $company->id)->whereKey($purchase->id)->lockForUpdate()->with(['supplier', 'lines'])->firstOrFail();
                if (ElectronicDocument::query()->where('company_id', $company->id)->where('purchase_id', $purchase->id)->exists()) {
                    throw new ApiException(ApiErrorCode::RETENTION_ALREADY_EXISTS, 'Esta compra ya tiene su comprobante de retención.', 409);
                }
                if (blank($purchase->supplier_document_number)) {
                    throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'La compra necesita el número de la factura del proveedor para retener.', 422);
                }
                $point = EmissionPoint::query()->where('company_id', $company->id)->where('public_id', $data['emissionPointPublicId'])->where('is_active', true)->first();
                $establishment = $point?->establishment()->where('is_active', true)->first();
                $settings = $company->fiscalSettings;
                if (! $point || ! $establishment || ! $settings?->sri_environment || ! $settings?->emission_type) {
                    throw new ApiException(ApiErrorCode::EMISSION_POINT_NOT_FOUND, 'Elige un punto de emisión activo de la empresa.', 422);
                }

                $withholdings = $this->withholdings($purchase, $data['withholdings']);
                $withheld = array_reduce($withholdings, fn (string $sum, array $row): string => bcadd($sum, $row['amount'], 2), '0.00');
                $customer = $this->supplierCustomer->execute($company, $purchase->supplier, $actor);

                $type = DocumentType::RETENTION;
                $sequential = $this->sequentialFormatter->format($this->sequences->next($company->id, $establishment->id, $point->id, $type, (string) $company->ruc));
                $numericCode = $this->numericCodes->generate();
                $issuedAt = now();
                $accessKey = $this->accessKeys->generate($issuedAt, $type, $company->ruc, $settings->sri_environment, $establishment->code, $point->code, $sequential, $numericCode, $settings->emission_type);

                $retention = ElectronicDocument::query()->create([
                    'company_id' => $company->id, 'purchase_id' => $purchase->id, 'customer_id' => $customer->id,
                    'establishment_id' => $establishment->id, 'emission_point_id' => $point->id, 'document_type' => $type,
                    'establishment_code' => $establishment->code, 'emission_point_code' => $point->code,
                    'sequential' => $sequential, 'full_number' => $this->numberFormatter->format($establishment->code, $point->code, $sequential),
                    'numeric_code' => $numericCode, 'access_key' => $accessKey, 'issue_date' => $issuedAt->toDateString(),
                    'status' => DocumentStatus::NUMBERED, 'fiscal_period' => $issuedAt->format('m/Y'),
                    'related_party' => (bool) ($data['relatedParty'] ?? false),
                    'support_code' => $data['supportCode'], 'support_document_code' => '01',
                    'support_document_number' => $purchase->supplier_document_number,
                    'support_document_date' => $purchase->issue_date->toDateString(),
                    'support_authorization_number' => $purchase->supplier_authorization_number,
                    'issuer_ruc' => $company->ruc, 'issuer_legal_name' => $company->legal_name, 'issuer_commercial_name' => $company->commercial_name,
                    'issuer_main_address' => $company->main_address, 'establishment_address' => $establishment->address,
                    'accounting_required' => $settings->accounting_required, 'special_taxpayer_number' => $settings->special_taxpayer_number,
                    'withholding_agent_number' => $settings->withholding_agent_number, 'artisan_qualification_number' => $settings->artisan_qualification_number,
                    'rimpe_type' => $settings->rimpe_type?->value,
                    'sri_environment' => $settings->sri_environment->value, 'emission_type' => $settings->emission_type->value,
                    'xml_version' => config('sri.retention.xml_version', '2.0.0'),
                    'provider_ruc' => (string) config('sri.software_provider.ruc'), 'provider_name' => config('sri.software_provider.name', 'Condore Software'),
                    'customer_identification_type' => $customer->identification_type->value,
                    'customer_identification' => $purchase->supplier->identification, 'customer_name' => $purchase->supplier->legal_name,
                    'customer_address' => $purchase->supplier->address, 'customer_email' => $purchase->supplier->email,
                    // The support document's amounts; what is withheld lives in its withholdings.
                    'total_without_taxes' => $purchase->subtotal, 'tax_total' => $purchase->tax_total, 'total' => $purchase->total,
                    'created_by' => $actor->id, 'numbered_at' => $issuedAt,
                ]);
                $retention->taxes()->createMany(array_map(fn (array $tax): array => [...$tax, 'company_id' => $company->id], $this->supportTaxes($purchase)));
                $retention->withholdings()->createMany(array_map(fn (array $row): array => [...$row, 'company_id' => $company->id], $withholdings));
                $retention->paymentMethods()->create([
                    'company_id' => $company->id, 'method_code' => $purchase->payment_method_code ?? '01',
                    'method_name' => $purchase->payment_method_name ?? 'SIN UTILIZACION DEL SISTEMA FINANCIERO', 'amount' => $purchase->total,
                ]);
                $this->lowerPayable($purchase, $withheld, $retention, $actor);
                $this->audit->record($actor, $company, 'retention.numbered', $retention, null, [
                    'fullNumber' => $retention->full_number, 'purchase' => $purchase->displayNumber(), 'withheld' => $withheld,
                ]);

                return $retention->load(['withholdings', 'taxes', 'paymentMethods']);
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'DOCUMENT_SEQUENCE_EXHAUSTED') {
                throw new ApiException(ApiErrorCode::DOCUMENT_SEQUENCE_EXHAUSTED, 'La secuencia de retenciones de este punto de emisión se agotó.', 409);
            }
            throw $exception;
        }
    }

    /**
     * Renta on the subtotal of the purchase, IVA on its IVA; each code once and the bases never above them.
     *
     * @param  list<array{codePublicId: string, taxableBase: string, percentage?: ?string}>  $rows
     * @return list<array<string, string>>
     */
    private function withholdings(Purchase $purchase, array $rows): array
    {
        $codes = SriWithholdingCode::query()->where('is_active', true)->whereIn('public_id', array_column($rows, 'codePublicId'))->get()->keyBy('public_id');
        $limits = [SriWithholdingCode::RENTA => (string) $purchase->subtotal, SriWithholdingCode::IVA => (string) $purchase->tax_total];
        $used = [SriWithholdingCode::RENTA => '0.00', SriWithholdingCode::IVA => '0.00'];
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $code = $codes[$row['codePublicId']] ?? throw new ApiException(ApiErrorCode::WITHHOLDING_CODE_NOT_FOUND, 'Uno de los códigos de retención no existe o no está vigente.', 422);
            if (isset($seen[$code->id])) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, "El código {$code->code} está repetido.", 422);
            }
            $seen[$code->id] = true;
            $base = bcadd((string) $row['taxableBase'], '0', 2);
            $percentage = bcadd((string) (filled($row['percentage'] ?? null) ? $row['percentage'] : $code->percentage), '0', 2);
            if (bccomp($percentage, '100', 2) > 0) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El porcentaje de retención va de 0 a 100.', 422);
            }
            $used[$code->tax] = bcadd($used[$code->tax], $base, 2);
            if (bccomp($base, '0', 2) <= 0 || bccomp($used[$code->tax], $limits[$code->tax], 2) > 0) {
                $limit = $limits[$code->tax];
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, $code->tax === SriWithholdingCode::IVA
                    ? "La base de la retención de IVA suma más que el IVA de la compra (\${$limit})."
                    : "La base de la retención de Renta suma más que el subtotal de la compra (\${$limit}).", 422, ['limit' => $limit]);
            }
            $result[] = [
                'tax_code' => $code->sriTaxCode(), 'withholding_code' => $code->code, 'name' => $code->name,
                'taxable_base' => $base, 'percentage' => $percentage, 'amount' => $this->round(bcdiv(bcmul($base, $percentage, 8), '100', 8)),
            ];
        }

        return $result;
    }

    /**
     * impuestosDocSustento: the IVA of the supplier's invoice per rate.
     *
     * @return list<array<string, string>>
     */
    private function supportTaxes(Purchase $purchase): array
    {
        $groups = [];
        foreach ($purchase->lines as $line) {
            $key = (string) $line->tax_percentage_code;
            $groups[$key] ??= ['tax_code' => '2', 'percentage_code' => $key, 'percentage' => (string) $line->tax_percentage, 'taxable_base' => '0.00', 'tax_amount' => '0.00'];
            $groups[$key]['taxable_base'] = bcadd($groups[$key]['taxable_base'], (string) $line->subtotal, 2);
            $groups[$key]['tax_amount'] = bcadd($groups[$key]['tax_amount'], (string) $line->tax_total, 2);
        }

        return array_values($groups);
    }

    /** On credit, the amount withheld is not paid to the supplier: it covers its installments from the oldest. */
    private function lowerPayable(Purchase $purchase, string $withheld, ElectronicDocument $retention, User $actor): void
    {
        if (! $purchase->is_credit || bccomp($withheld, '0', 2) <= 0) {
            return;
        }
        $installments = $purchase->installments()->get();
        $balance = bcsub((string) $installments->sum('amount'), (string) $installments->sum('paid_amount'), 2);
        $amount = bccomp($withheld, $balance, 2) > 0 ? $balance : $withheld;
        if (bccomp($amount, '0', 2) > 0) {
            $this->payables->pay($purchase, $amount, 'Retención '.$retention->full_number, $actor);
        }
    }

    private function round(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }
}
