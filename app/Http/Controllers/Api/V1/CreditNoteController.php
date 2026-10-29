<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\FiscalDocuments\Actions\ContinueInvoiceIssuanceAction;
use App\Domains\FiscalDocuments\Actions\CreateCreditNoteAction;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ElectronicDocumentResource;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Credit notes (codDoc 04) of an authorized invoice: total or partial returns. */
final class CreditNoteController extends Controller
{
    public function index(ElectronicDocument $invoice): AnonymousResourceCollection
    {
        $this->authorize('view', $invoice);

        return ElectronicDocumentResource::collection($invoice->creditNotes()->with(['lines.taxes', 'taxes', 'modifiedDocument'])->orderByDesc('id')->get());
    }

    /** Numbers the credit note and continues it to XML → signature → SRI, like an invoice. */
    public function store(Request $request, ElectronicDocument $invoice, CreateCreditNoteAction $create, ContinueInvoiceIssuanceAction $continue): JsonResponse
    {
        $this->authorize('process', $invoice);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.lineNumber' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,6})?$/'],
        ]);

        $creditNote = $create->execute($invoice, trim($data['reason']), $data['lines'], $request->user());
        try {
            $creditNote = $continue->execute($creditNote, $request->user());
        } catch (ApiException) {
            // Numbered and kept: Ventas shows why it stopped and offers the retry, as with invoices.
            $creditNote = $creditNote->refresh();
        }

        return (new ElectronicDocumentResource($creditNote->load(['lines.taxes', 'taxes', 'modifiedDocument'])))->response()->setStatusCode(201);
    }
}
