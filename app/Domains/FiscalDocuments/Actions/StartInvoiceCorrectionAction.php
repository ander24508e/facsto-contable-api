<?php

namespace App\Domains\FiscalDocuments\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * POST /invoices/{invoice}/correct — an invoice the SRI returned or did not authorize because of its data
 * opens its sale for editing again. Issuing the sale afterwards keeps the number and generates a new
 * access key (CreateInvoiceFromSaleAction::correct).
 */
final class StartInvoiceCorrectionAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(ElectronicDocument $invoice, User $actor): Sale
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($company, $invoice, $actor): Sale {
            $document = ElectronicDocument::query()->where('company_id', $company->id)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! in_array($document->status, [DocumentStatus::RETURNED, DocumentStatus::NOT_AUTHORIZED], true)) {
                throw new ApiException(ApiErrorCode::INVALID_DOCUMENT_TRANSITION, 'Sólo una factura devuelta o no autorizada por el SRI se puede corregir.', 409, [
                    'status' => $document->status->value,
                ]);
            }
            $sale = $company->sales()->whereKey($document->sale_id)->lockForUpdate()->firstOrFail();
            if ($sale->status !== SaleStatus::IN_CORRECTION) {
                $sale->forceFill(['status' => SaleStatus::IN_CORRECTION])->save();
                $this->audit->record($actor, $company, 'invoice.correction_started', $document, ['status' => $document->status->value], [
                    'fullNumber' => $document->full_number, 'salePublicId' => $sale->public_id,
                ]);
            }

            return $sale->refresh()->load(['customer', 'creator', 'details.taxes', 'paymentMethods', 'electronicDocument']);
        });
    }
}
