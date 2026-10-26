<?php

namespace App\Domains\FiscalDocuments\Services;

use App\Domains\Companies\Models\Company;
use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Models\DocumentArtifact;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DocumentArtifactStorage
{
    public function store(ElectronicDocument $document, DocumentArtifactType $type, string $contents): DocumentArtifact
    {
        $company = Company::query()->findOrFail($document->company_id);
        $diskName = (string) config('sri.storage.disk', 'private');
        $basePath = trim((string) config('sri.storage.base_path', 'sri'), '/');
        $version = ((int) DocumentArtifact::query()
            ->where('electronic_document_id', $document->id)
            ->where('type', $type->value)
            ->max('version')) + 1;
        $filename = match ($type) {
            DocumentArtifactType::UNSIGNED_XML => 'unsigned-v'.$version.'.xml',
            DocumentArtifactType::SIGNED_XML => 'signed-v'.$version.'.xml',
            DocumentArtifactType::AUTHORIZED_XML => 'authorized-v'.$version.'.xml',
            DocumentArtifactType::RIDE => 'ride-v'.$version.'.pdf',
        };
        $path = $basePath.'/companies/'.$company->public_id.'/documents/'.$document->public_id.'/'.$filename;
        $disk = Storage::disk($diskName);
        $disk->put($path, $contents);

        try {
            return DB::transaction(fn (): DocumentArtifact => DocumentArtifact::query()->create([
                'company_id' => $document->company_id,
                'electronic_document_id' => $document->id,
                'type' => $type,
                'version' => $version,
                'disk' => $diskName,
                'path' => $path,
                'sha256' => hash('sha256', $contents),
                'size_bytes' => strlen($contents),
                'mime_type' => $type === DocumentArtifactType::RIDE ? 'application/pdf' : 'application/xml',
            ]));
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    public function latest(ElectronicDocument $document, DocumentArtifactType $type): DocumentArtifact
    {
        return DocumentArtifact::query()
            ->where('company_id', $document->company_id)
            ->where('electronic_document_id', $document->id)
            ->where('type', $type->value)
            ->orderByDesc('version')
            ->firstOrFail();
    }

    public function read(DocumentArtifact $artifact): string
    {
        $contents = Storage::disk($artifact->disk)->get($artifact->path);
        if (! is_string($contents)) {
            throw new RuntimeException('The private document artifact could not be read.');
        }

        return $contents;
    }

    public function discardFile(DocumentArtifact $artifact): void
    {
        Storage::disk($artifact->disk)->delete($artifact->path);
    }
}
