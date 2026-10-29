<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\FiscalDocuments\Actions\ContinueInvoiceIssuanceAction;
use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\Ride\Actions\GenerateRideAction;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/** Ventas in volume: retry several invoices at once, or download their XML and RIDE in one ZIP. */
final class InvoiceBulkController extends Controller
{
    private const MAX = 100;

    /** POST /invoices/bulk/retry-sri — each invoice resumes on its own; one failure does not stop the rest. */
    public function retry(Request $request, CompanyContext $context, ContinueInvoiceIssuanceAction $continue): JsonResponse
    {
        $results = [];
        foreach ($this->documents($request, $context) as $document) {
            $this->authorize('process', $document);
            try {
                $document = $continue->retry($document, $request->user());
                $results[] = ['publicId' => $document->public_id, 'fullNumber' => $document->full_number, 'ok' => true, 'status' => $document->status->value, 'errorCode' => null];
            } catch (ApiException $exception) {
                $results[] = ['publicId' => $document->public_id, 'fullNumber' => $document->full_number, 'ok' => false, 'status' => $document->refresh()->status->value, 'errorCode' => $exception->errorCode->value];
            }
        }

        return response()->json(['data' => $results, 'meta' => [
            'succeeded' => count(array_filter($results, fn (array $result): bool => $result['ok'])),
            'failed' => count(array_filter($results, fn (array $result): bool => ! $result['ok'])),
        ]]);
    }

    /** POST /invoices/bulk/export — authorized XML and RIDE of every authorized invoice selected. */
    public function export(Request $request, CompanyContext $context, DocumentArtifactStorage $storage, GenerateRideAction $ride): BinaryFileResponse
    {
        $documents = $this->documents($request, $context)->filter(fn (ElectronicDocument $document): bool => $document->status === DocumentStatus::AUTHORIZED);
        if ($documents->isEmpty()) {
            throw new ApiException(ApiErrorCode::DOCUMENT_ARTIFACT_NOT_FOUND, 'Selecciona al menos una factura autorizada por el SRI.', 422);
        }

        $path = tempnam(sys_get_temp_dir(), 'facsto-export-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($documents as $document) {
            $this->authorize('view', $document);
            $zip->addFromString($document->full_number.'.xml', $storage->read($storage->latest($document, DocumentArtifactType::AUTHORIZED_XML)));
            $zip->addFromString($document->full_number.'.pdf', $storage->read($ride->execute($document)));
        }
        $zip->close();

        return response()->download($path, 'facturas-'.now()->format('Ymd-His').'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }

    /** @return Collection<int, ElectronicDocument> */
    private function documents(Request $request, CompanyContext $context): Collection
    {
        $ids = $request->validate([
            'invoicePublicIds' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'invoicePublicIds.*' => ['required', 'uuid', 'distinct'],
        ])['invoicePublicIds'];

        return ElectronicDocument::query()
            ->where('company_id', $context->company()->id)
            ->whereIn('public_id', $ids)
            ->with('sale')
            ->orderBy('id')
            ->get();
    }
}
