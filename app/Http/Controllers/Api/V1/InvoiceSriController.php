<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\FiscalDocuments\Actions\ContinueInvoiceIssuanceAction;
use App\Domains\FiscalDocuments\Actions\StartInvoiceCorrectionAction;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Ride\Services\Code128Barcode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ElectronicDocumentResource;
use App\Http\Resources\Api\V1\InvoiceSriStatusResource;
use App\Http\Resources\Api\V1\SaleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvoiceSriController extends Controller
{
    /** State, every SRI attempt with its official messages, and the delivery result. */
    public function status(ElectronicDocument $invoice): InvoiceSriStatusResource
    {
        $this->authorize('view', $invoice);

        return new InvoiceSriStatusResource($invoice->load([
            'submissions' => fn ($query) => $query->orderByDesc('id')->limit(50),
            'submissions.messages',
            'deliveries',
            'artifacts',
        ]));
    }

    /** GET /invoices/{invoice}/barcode — the Code 128 of the access key, the same one printed on the RIDE. */
    public function barcode(ElectronicDocument $invoice, Code128Barcode $barcode): JsonResponse
    {
        $this->authorize('view', $invoice);

        return response()->json(['data' => [
            'accessKey' => $invoice->access_key,
            'svg' => $barcode->svg($invoice->access_key, 1.0, 48),
        ]]);
    }

    /** Controlled retry: resumes F05/F06 from the current state with the same number and access key. */
    public function retry(ElectronicDocument $invoice, ContinueInvoiceIssuanceAction $continue, Request $request): ElectronicDocumentResource
    {
        $this->authorize('process', $invoice);

        return new ElectronicDocumentResource($continue->retry($invoice, $request->user()));
    }

    /** Opens the sale of a returned / not authorized invoice for correction; issuing it again keeps the number. */
    public function correct(ElectronicDocument $invoice, StartInvoiceCorrectionAction $start, Request $request): SaleResource
    {
        $this->authorize('process', $invoice);

        return new SaleResource($start->execute($invoice, $request->user()));
    }
}
