<?php

namespace App\Domains\Sri\Jobs;

use App\Domains\Sri\Actions\ProcessSriAuthorizationAction;
use App\Domains\Sri\Enums\SriAuthorizationStatus;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RECEIVED → authorization query with the access key. While the SRI answers "en proceso" the job
 * schedules itself again; past the poll limit the scheduler command resumes it later.
 */
final class CheckAuthorizationJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $documentId, public readonly int $poll = 1)
    {
        $this->onQueue((string) config('sri.queues.authorization', 'sri-authorization'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return $this->documentId.'|AUTHORIZATION';
    }

    public function handle(ProcessSriAuthorizationAction $authorization): void
    {
        $status = $authorization->execute($this->documentId);
        if ($status === SriAuthorizationStatus::PENDING && $this->poll < (int) config('sri.retry.authorization_max_polls', 10)) {
            self::dispatch($this->documentId, $this->poll + 1)
                ->delay(now()->addSeconds((int) config('sri.retry.authorization_delay', 30)));
        }
    }

    /** The SRI already holds the document: it is never marked FAILED here, the scheduler asks again. */
    public function failed(?Throwable $exception): void
    {
        Log::warning('SRI authorization query failed; the scheduler will retry it.', [
            'electronic_document_id' => $this->documentId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
