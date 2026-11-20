<?php

namespace App\Shared\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;

/**
 * Regla 31 (sincronización entre usuarios): a resource changed and the screens that show it must ask the API again.
 * It carries no data, only what changed (its type, public id, action and version), so nobody receives information the
 * API would not give them; the screen refetches with its own permissions. It goes out only after the transaction
 * commits (a rolled-back change announces nothing) and through the queue (Horizon), never delaying the request.
 */
final class ResourceChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    /** Unique per notice: the browser drops duplicates. */
    public readonly string $id;

    public function __construct(
        public readonly string $channel,
        public readonly string $resource,
        public readonly ?string $publicId,
        public readonly string $action,
        public readonly ?string $version,
    ) {
        $this->id = (string) Str::uuid7();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'changed';
    }

    /** @return array{id: string, resource: string, publicId: ?string, action: string, version: ?string} */
    public function broadcastWith(): array
    {
        return ['id' => $this->id, 'resource' => $this->resource, 'publicId' => $this->publicId, 'action' => $this->action, 'version' => $this->version];
    }
}
