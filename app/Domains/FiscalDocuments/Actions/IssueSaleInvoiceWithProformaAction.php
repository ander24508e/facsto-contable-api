<?php

namespace App\Domains\FiscalDocuments\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Proformas\Models\Proforma;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class IssueSaleInvoiceWithProformaAction
{
    public function __construct(
        private readonly IssueSaleInvoiceAction $issue,
        private readonly CompanyContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(Sale $sale, User $actor, ?string $cashSessionPublicId = null): ElectronicDocument
    {
        $document = $this->issue->execute($sale, $actor, $cashSessionPublicId);
        $company = $this->context->company();

        if ($document->exists) {
            DB::transaction(function () use ($sale, $actor, $company, $document): void {
                $proforma = Proforma::query()
                    ->where('company_id', $company->id)
                    ->where('converted_sale_id', $sale->id)
                    ->lockForUpdate()
                    ->first();
                if (! $proforma) {
                    return;
                }

                $this->audit->record($actor, $company, 'proforma.converted_to_invoice', $proforma, [
                    'number' => $proforma->displayNumber(),
                ], [
                    'saleNumber' => $sale->displayNumber(),
                    'invoiceNumber' => $document->full_number,
                    'invoicePublicId' => $document->public_id,
                ]);
                $proforma->delete();
            });
        }

        return $document;
    }
}
