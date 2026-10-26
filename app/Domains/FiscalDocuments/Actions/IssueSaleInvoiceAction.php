<?php

namespace App\Domains\FiscalDocuments\Actions;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Models\Sale;
use App\Models\User;

/**
 * POST /sales/{sale}/issue — the single business command. F04 numbers the sale once (behind
 * ElectronicDocumentIssuanceReadiness); F05 builds and signs; F06 is queued. Calling it again
 * resumes from the current state without consuming another sequential.
 */
final class IssueSaleInvoiceAction
{
    public function __construct(
        private readonly CreateInvoiceFromSaleAction $number,
        private readonly ContinueInvoiceIssuanceAction $continue,
    ) {}

    public function execute(Sale $sale, User $actor): ElectronicDocument
    {
        return $this->continue->execute($this->number->execute($sale, $actor), $actor);
    }
}
