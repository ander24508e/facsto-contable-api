<?php

namespace App\Domains\Sri\Jobs;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\Actions\ProcessSriReceptionAction;
use App\Domains\Sri\Soap\SriResponseException;
use App\Domains\Sri\Soap\SriTransportException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * QUEUED → SRI reception. A document that could not reach the SRI (SRI down, network, HTTP 5xx)
 * is retried with the same access key until 23:59:59 of its issue date; retryUntil() takes
 * precedence over any worker --tries.
 */
final class SubmitDocumentToSriJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue((string) config('sri.queues.submit', 'sri-submit'));
    }

    public function retryUntil(): ?DateTimeInterface
    {
        return ElectronicDocument::query()->find($this->documentId)?->sriRetryDeadline();
    }

    /** @return list<int> the last wait repeats until the deadline */
    public function backoff(): array
    {
        return array_map('intval', (array) config('sri.retry.submit_backoff', [30, 60, 120, 300, 600]));
    }

    /** One reception in flight per document. */
    public function uniqueId(): string
    {
        return $this->documentId.'|RECEPTION';
    }

    public function handle(ProcessSriReceptionAction $reception): void
    {
        $reception->execute($this->documentId);
    }

    public function failed(?Throwable $exception): void
    {
        $document = ElectronicDocument::query()->find($this->documentId);
        $code = match (true) {
            $document !== null && ! $document->sriRetryWindowOpen() => 'SRI_RETRY_DEADLINE_EXCEEDED',
            $exception instanceof SriTransportException || $exception instanceof SriResponseException => $exception->reasonCode,
            default => 'SRI_SUBMISSION_FAILED',
        };
        app(ProcessSriReceptionAction::class)->markFailed($this->documentId, $code);
    }
}
