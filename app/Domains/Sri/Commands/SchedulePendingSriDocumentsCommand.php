<?php

namespace App\Domains\Sri\Commands;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\Actions\QueueDocumentForSriAction;
use App\Domains\Sri\Jobs\CheckAuthorizationJob;
use App\Domains\Sri\Jobs\SubmitDocumentToSriJob;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resumes documents that waited too long for the SRI (lost jobs, exhausted polls, outages) and
 * re-queues technical failures while their issue day lasts (until 23:59:59, Ecuador).
 */
final class SchedulePendingSriDocumentsCommand extends Command
{
    protected $signature = 'sri:dispatch-pending {--limit=200}';

    protected $description = 'Vuelve a encolar los comprobantes que esperan respuesta del SRI o que no pudieron enviarse hoy.';

    public function handle(QueueDocumentForSriAction $queue): int
    {
        $staleBefore = now()->subMinutes((int) config('sri.retry.stale_after_minutes', 10));
        $today = now((string) config('sri.timezone', 'America/Guayaquil'))->toDateString();
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

        // A signed document that could not reach the SRI keeps being retried during its issue day.
        $failed = ElectronicDocument::query()
            ->where('status', DocumentStatus::FAILED->value)
            ->whereNotNull('signed_at')
            ->whereDate('issue_date', '>=', $today)
            ->where($stale)->orderBy('id')->limit($limit)->get();
        foreach ($failed as $document) {
            try {
                $queue->execute($document);
            } catch (Throwable $exception) {
                Log::warning('A failed SRI submission could not be re-queued.', ['electronic_document_id' => $document->id, 'exception' => $exception::class]);
            }
        }

        $this->info("Autorizaciones: {$authorizations->count()} · Recepciones: {$receptions->count()} · Reintentos del día: {$failed->count()}");

        return self::SUCCESS;
    }
}
