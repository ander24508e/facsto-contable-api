<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\FiscalDocuments\Actions\IssueSaleInvoiceAction;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Models\Sale;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ElectronicDocumentResource;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class InvoiceController extends Controller
{
    public function index(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ElectronicDocument::class);
        $validated = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'status' => ['sometimes', 'string', 'max:32']]);
        $query = ElectronicDocument::query()->where('company_id', $context->company()->id)->with(['sale', 'lines.taxes', 'taxes', 'paymentMethods'])->latest('issue_date');
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return ElectronicDocumentResource::collection($query->paginate($validated['per_page'] ?? 15));
    }

    /**
     * GET /invoices/lookup?q= — a pasted access key (also a previous one of a corrected invoice), a full
     * number 001-001-000000123 or just its sequential opens that invoice directly.
     */
    public function lookup(Request $request, CompanyContext $context): ElectronicDocumentResource
    {
        $this->authorize('viewAny', ElectronicDocument::class);
        $term = preg_replace('/\s+/', '', (string) $request->validate(['q' => ['required', 'string', 'max:60']])['q']);
        $query = ElectronicDocument::query()->where('company_id', $context->company()->id)->with('sale');
        $document = match (true) {
            preg_match('/^\d{49}$/', $term) === 1 => (clone $query)->where('access_key', $term)->first()
                ?? (clone $query)->whereJsonContains('previous_access_keys', $term)->first(),
            preg_match('/^\d{3}-\d{3}-\d{1,9}$/', $term) === 1 => (clone $query)->where('full_number', substr($term, 0, 8).str_pad(substr($term, 8), 9, '0', STR_PAD_LEFT))->latest('id')->first(),
            preg_match('/^\d{1,9}$/', $term) === 1 => (clone $query)->where('sequential', str_pad($term, 9, '0', STR_PAD_LEFT))->latest('id')->first(),
            default => null,
        };
        if (! $document) {
            throw new ApiException(ApiErrorCode::INVOICE_NOT_FOUND, 'No hay una factura con esa clave de acceso o número.', 404);
        }

        return new ElectronicDocumentResource($document);
    }

    /** F04 → F05 → F06 queue. 201 when the sale was numbered by this call, 200 when it only resumed. */
    public function issue(Sale $sale, IssueSaleInvoiceAction $issue, Request $request): JsonResponse
    {
        $this->authorize('create', ElectronicDocument::class);
        $numberedNow = ! ElectronicDocument::query()->where('sale_id', $sale->id)->exists();
        $document = $issue->execute($sale, $request->user());

        return (new ElectronicDocumentResource($document))->response()->setStatusCode($numberedNow ? 201 : 200);
    }

    public function show(ElectronicDocument $invoice): ElectronicDocumentResource
    {
        $this->authorize('view', $invoice);

        return new ElectronicDocumentResource($invoice->load(['sale', 'lines.taxes', 'taxes', 'paymentMethods']));
    }
}
