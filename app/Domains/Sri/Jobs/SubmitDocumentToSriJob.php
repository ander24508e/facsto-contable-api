<?php

namespace App\Domains\Sri\Jobs;

use App\Domains\Sri\Actions\ProcessSriReceptionAction;
use App\Domains\Sri\Soap\SriResponseException;
use App\Domains\Sri\Soap\SriTransportException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** QUEUED → SRI reception. Technical failures retry with exponential backoff and the same access key. */
final class SubmitDocumentToSriJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $documentId)
    {
        $this->tries = (int) config('sri.retry.submit_tries', 3);
        $this->onQueue((string) config('sri.queues.submit', 'sri-submit'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_map('intval', (array) config('sri.retry.submit_backoff', [30, 120, 600]));
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
        $code = $exception instanceof SriTransportException || $exception instanceof SriResponseException
            ? $exception->reasonCode
            : 'SRI_SUBMISSION_FAILED';
        app(ProcessSriReceptionAction::class)->markFailed($this->documentId, $code);
    }
}
