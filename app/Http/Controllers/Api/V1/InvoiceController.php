<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\FiscalDocuments\Actions\IssueSaleInvoiceAction;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Models\Sale;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ElectronicDocumentResource;
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
