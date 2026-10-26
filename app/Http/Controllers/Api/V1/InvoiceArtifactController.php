<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\DocumentArtifact;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\Ride\Actions\GenerateRideAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DocumentArtifactResource;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Private, versioned and tenant-safe artifacts: the route model is checked against the company context. */
final class InvoiceArtifactController extends Controller
{
    public function index(ElectronicDocument $invoice): AnonymousResourceCollection
    {
        $this->authorize('view', $invoice);

        return DocumentArtifactResource::collection(
            $invoice->artifacts()->orderBy('type')->orderByDesc('version')->get(),
        );
    }

    public function download(ElectronicDocument $invoice, string $type, DocumentArtifactStorage $storage, GenerateRideAction $ride): Response
    {
        $this->authorize('view', $invoice);
        $artifactType = DocumentArtifactType::tryFrom(strtoupper(str_replace('-', '_', $type)))
            ?? throw new ApiException(ApiErrorCode::DOCUMENT_ARTIFACT_NOT_FOUND, 'El tipo de archivo solicitado no existe.', 404);

        $artifact = $artifactType === DocumentArtifactType::RIDE && $invoice->status === DocumentStatus::AUTHORIZED
            ? $ride->execute($invoice)
            : $invoice->artifacts()->where('type', $artifactType->value)->orderByDesc('version')->first();
        if (! $artifact instanceof DocumentArtifact) {
            throw new ApiException(ApiErrorCode::DOCUMENT_ARTIFACT_NOT_FOUND, 'El comprobante todavía no tiene este archivo.', 404, ['type' => $artifactType->value]);
        }

        $contents = $storage->read($artifact);
        if (! hash_equals($artifact->sha256, hash('sha256', $contents))) {
            throw new ApiException(ApiErrorCode::DOCUMENT_ARTIFACT_INTEGRITY_FAILED, 'El archivo guardado no coincide con su huella SHA-256.', 409, ['type' => $artifactType->value]);
        }

        $extension = $artifactType === DocumentArtifactType::RIDE ? 'pdf' : 'xml';
        $filename = $invoice->full_number.'-'.strtolower(str_replace('_', '-', $artifactType->value)).'.'.$extension;

        return response($contents, 200, [
            'Content-Type' => $artifact->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Artifact-Sha256' => $artifact->sha256,
        ]);
    }
}
