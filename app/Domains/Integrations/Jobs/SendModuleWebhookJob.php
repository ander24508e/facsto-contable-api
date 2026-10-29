<?php

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * POSTs the event to the module's webhook URL, signed with its secret:
 * X-Facsto-Signature = "sha256=" . HMAC-SHA256("{timestamp}.{body}", secret). The module checks the
 * signature and the timestamp (replay window) and answers 2xx; anything else is retried.
 */
final class SendModuleWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800, 3600];

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue((string) config('sri.queues.delivery', 'delivery'));
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('apiClient')->find($this->deliveryId);
        $client = $delivery?->apiClient;
        if (! $delivery || $delivery->status === 'DELIVERED' || ! $client?->is_active || blank($client->webhook_url)) {
            return;
        }

        $timestamp = (string) now()->getTimestamp();
        $body = json_encode(['id' => $delivery->public_id, 'event' => $delivery->event, 'createdAt' => $delivery->created_at?->toIso8601String(), 'data' => $delivery->payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $client->webhook_secret);
        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        try {
            $response = Http::timeout(10)->withHeaders([
                'X-Facsto-Event' => $delivery->event,
                'X-Facsto-Delivery' => $delivery->public_id,
                'X-Facsto-Timestamp' => $timestamp,
                'X-Facsto-Signature' => $signature,
            ])->withBody((string) $body, 'application/json')->post((string) $client->webhook_url);
        } catch (Throwable $exception) {
            $delivery->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 255)])->save();
            throw $exception;
        }

        $delivery->forceFill(['response_status' => $response->status()])->save();
        if (! $response->successful()) {
            $delivery->forceFill(['last_error' => 'HTTP '.$response->status()])->save();
            throw new RuntimeException('Module webhook answered HTTP '.$response->status());
        }
        $delivery->forceFill(['status' => 'DELIVERED', 'delivered_at' => now(), 'last_error' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        WebhookDelivery::query()->whereKey($this->deliveryId)->where('status', '<>', 'DELIVERED')->update(['status' => 'FAILED']);
    }
}
