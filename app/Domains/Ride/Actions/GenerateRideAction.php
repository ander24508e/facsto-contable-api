<?php

namespace App\Domains\Ride\Actions;

use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\DocumentArtifact;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\Ride\Services\RideDataFactory;
use App\Domains\Ride\Services\RidePdfGenerator;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** F07.2: the RIDE exists only for an AUTHORIZED document and is stored as a private, versioned artifact. */
final class GenerateRideAction
{
    public function __construct(
        private readonly RideDataFactory $data,
        private readonly RidePdfGenerator $pdf,
        private readonly DocumentArtifactStorage $artifacts,
    ) {}

    public function execute(ElectronicDocument $document): DocumentArtifact
    {
        if ($document->status !== DocumentStatus::AUTHORIZED) {
            throw new ApiException(ApiErrorCode::DOCUMENT_ARTIFACT_NOT_FOUND, 'El RIDE sólo existe para un comprobante autorizado por el SRI.', 409);
        }
        $existing = $document->artifacts()->where('type', DocumentArtifactType::RIDE->value)->orderByDesc('version')->first();
        if ($existing instanceof DocumentArtifact) {
            return $existing;
        }

        $contents = $this->pdf->render($this->data->make($document));

        return DB::transaction(function () use ($document, $contents): DocumentArtifact {
            ElectronicDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $existing = $document->artifacts()->where('type', DocumentArtifactType::RIDE->value)->orderByDesc('version')->first();

            return $existing ?? $this->artifacts->store($document, DocumentArtifactType::RIDE, $contents);
        });
    }
}
