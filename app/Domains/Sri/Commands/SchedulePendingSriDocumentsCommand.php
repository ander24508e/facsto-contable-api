<?php

namespace App\Domains\Sri\Commands;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\Jobs\CheckAuthorizationJob;
use App\Domains\Sri\Jobs\SubmitDocumentToSriJob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/** Resumes documents that waited too long for the SRI (lost jobs, exhausted polls, outages). */
final class SchedulePendingSriDocumentsCommand extends Command
{
    protected $signature = 'sri:dispatch-pending {--limit=200}';

    protected $description = 'Vuelve a encolar los comprobantes que esperan respuesta del SRI desde hace demasiado tiempo.';

    public function handle(): int
    {
        $staleBefore = now()->subMinutes((int) config('sri.retry.stale_after_minutes', 10));
        $limit = max(1, (int) $this->option('limit'));
        $stale = fn (Builder $query) => $query->whereNull('last_sri_status_at')->orWhere('last_sri_status_at', '<', $staleBefore);

        $authorizations = ElectronicDocument::query()
            ->whereIn('status', [DocumentStatus::RECEIVED->value, DocumentStatus::PENDING_AUTHORIZATION->value])
            ->where($stale)->orderBy('id')->limit($limit)->pluck('id');
        foreach ($authorizations as $id) {
            CheckAuthorizationJob::dispatch($id);
        }

        $receptions = ElectronicDocument::query()
            ->where('status', DocumentStatus::QUEUED->value)
            ->where('updated_at', '<', $staleBefore)
            ->where($stale)->orderBy('id')->limit($limit)->pluck('id');
        foreach ($receptions as $id) {
            SubmitDocumentToSriJob::dispatch($id);
        }

        $this->info("Autorizaciones: {$authorizations->count()} · Recepciones: {$receptions->count()}");

        return self::SUCCESS;
    }
}
