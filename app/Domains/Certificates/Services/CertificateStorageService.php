<?php

namespace App\Domains\Certificates\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class CertificateStorageService
{
    public function store(UploadedFile $file, string $companyPublicId, string $certificatePublicId): string
    {
        $path = "companies/{$companyPublicId}/certificates/{$certificatePublicId}.p12";
        $file->storeAs(dirname($path), basename($path), 'private');

        return $path;
    }

    public function delete(string $path): void
    {
        Storage::disk('private')->delete($path);
    }
}
