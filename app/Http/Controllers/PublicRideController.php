<?php

namespace App\Http\Controllers;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentArtifactStorage;
use App\Domains\Ride\Actions\GenerateRideAction;
use Illuminate\Http\Response;

/** The RIDE behind a signed share link: no session, only the signature and its expiry protect it. */
final class PublicRideController extends Controller
{
    public function __invoke(string $invoice, GenerateRideAction $ride, DocumentArtifactStorage $storage): Response
    {
        $document = ElectronicDocument::query()->where('public_id', $invoice)->where('status', DocumentStatus::AUTHORIZED->value)->firstOrFail();
        $artifact = $ride->execute($document);
        $contents = $storage->read($artifact);
        abort_unless(hash_equals($artifact->sha256, hash('sha256', $contents)), 409);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->full_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
