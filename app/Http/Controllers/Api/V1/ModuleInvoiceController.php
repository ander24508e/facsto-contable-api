<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\Integrations\Actions\IssueModuleInvoiceAction;
use App\Domains\Integrations\Models\ApiClient;
use App\Domains\Ride\Actions\GenerateRideAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ElectronicDocumentResource;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** The API for modules (Bearer fct_… token): issue, follow and download their invoices. */
final class ModuleInvoiceController extends Controller
{
    public function store(Request $request, IssueModuleInvoiceAction $issue): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || mb_strlen($key) > 120) {
            throw new ApiException(ApiErrorCode::IDEMPOTENCY_KEY_REQUIRED, 'Envía la cabecera Idempotency-Key (hasta 120 caracteres) para no duplicar facturas.', 422);
        }
        $data = $request->validate([
            'sourceReference' => ['nullable', 'string', 'max:120'],
            'customer' => ['nullable', 'array'],
            'customer.identificationType' => ['nullable', Rule::in(['RUC', 'CEDULA', 'PASSPORT', 'FOREIGN_IDENTIFICATION'])],
            'customer.identification' => ['required_with:customer', 'string', 'max:20'],
            'customer.legalName' => ['required_with:customer', 'string', 'max:180'],
            'customer.email' => ['nullable', 'email', 'max:254'],
            'customer.address' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.itemCode' => ['required', 'string', 'max:25'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,9}(\.\d{1,6})?$/'],
            'lines.*.discountAmount' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'payments' => ['sometimes', 'array', 'max:10'],
            'payments.*.code' => ['required', 'string', 'max:12'],
            'payments.*.amount' => ['nullable', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'payments.*.reference' => ['nullable', 'string', 'max:120'],
            'payments.*.term' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'payments.*.timeUnit' => ['nullable', 'required_with:payments.*.term', Rule::in(['DAY', 'MONTH'])],
            'tipAmount' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $issue->execute($this->client($request), $key, $data, $request->user());

        return response()->json([
            'data' => [
                'salePublicId' => $result['sale']->public_id,
                'sourceReference' => $result['sale']->source_reference,
                'invoice' => $result['invoice'] ? new ElectronicDocumentResource($result['invoice']->load(['lines.taxes', 'taxes', 'paymentMethods'])) : null,
                'error' => $result['error'] ? ['code' => $result['error']->errorCode->value, 'message' => $result['error']->getMessage(), 'details' => $result['error']->details] : null,
            ],
            'meta' => ['replayed' => $result['replayed']],
        ], $result['replayed'] ? 200 : ($result['error'] ? 202 : 201));
    }

    public function show(Request $request, string $invoice, CompanyContext $context): ElectronicDocumentResource
    {
        return new ElectronicDocumentResource($this->document($request, $invoice, $context)->load(['lines.taxes', 'taxes', 'paymentMethods']));
    }

    public function ride(Request $request, string $invoice, CompanyContext $context, GenerateRideAction $ride, DocumentArtifactStorage $storage): Response
    {
        $document = $this->document($request, $invoice, $context);
        $contents = $storage->read($ride->execute($document));

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->full_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** A module only sees the documents of the sales it sent (and their credit notes). */
    private function document(Request $request, string $publicId, CompanyContext $context): ElectronicDocument
    {
        $client = $this->client($request);

        return ElectronicDocument::query()->where('company_id', $context->company()->id)->where('public_id', $publicId)
            ->where(fn ($query) => $query
                ->whereHas('sale', fn ($sale) => $sale->where('api_client_id', $client->id))
                ->orWhereHas('modifiedDocument.sale', fn ($sale) => $sale->where('api_client_id', $client->id)))
            ->first() ?? throw new ApiException(ApiErrorCode::INVOICE_NOT_FOUND, 'El comprobante no existe para este módulo.', 404);
    }

    private function client(Request $request): ApiClient
    {
        return $request->attributes->get('apiClient');
    }
}
