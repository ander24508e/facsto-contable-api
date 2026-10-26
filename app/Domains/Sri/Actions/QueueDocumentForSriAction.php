<?php

namespace App\Domains\Sri\Actions;

use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\DocumentStateMachine;
use App\Domains\Sri\Jobs\SubmitDocumentToSriJob;
use App\Domains\Sri\Services\SriTransmissionGate;
use Illuminate\Support\Facades\DB;

/**
 * SIGNED → QUEUED and hands the document to Redis/Horizon. While the transmission gate is closed
 * the document stays SIGNED: FACSTO never pretends that something was sent.
 */
final class QueueDocumentForSriAction
{
    public function __construct(
        private readonly DocumentStateMachine $states,
        private readonly SriTransmissionGate $gate,
    ) {}

    public function execute(ElectronicDocument $document): ElectronicDocument
    {
        return DB::transaction(function () use ($document): ElectronicDocument {
            $locked = ElectronicDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === DocumentStatus::QUEUED) {
                // Idempotent: the unique job guarantees a single reception in flight.
                SubmitDocumentToSriJob::dispatch($locked->id)->afterCommit();

                return $locked;
            }
            if (! ($locked->status === DocumentStatus::FAILED && $locked->signed_at !== null)) {
                $this->states->assertCanSubmitToSri($locked);
            }

            $settings = CompanyFiscalSettings::query()->where('company_id', $locked->company_id)->first();
            if ($this->gate->blockingReason($settings) !== null) {
                return $locked;
            }

            $locked = $this->states->transition($locked, DocumentStatus::QUEUED, ['last_error_code' => null]);
            SubmitDocumentToSriJob::dispatch($locked->id)->afterCommit();

            return $locked;
        });
    }
}
