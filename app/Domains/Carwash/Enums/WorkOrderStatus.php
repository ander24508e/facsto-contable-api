<?php

namespace App\Domains\Carwash\Enums;

/**
 * PENDING → ARRIVED → IN_PROGRESS → READY → DELIVERED, y CANCELLED antes de empezar a lavar.
 * DELIVERED exige que FACSTO ya haya numerado la factura (ver WorkOrderStateMachine).
 */
enum WorkOrderStatus: string
{
    case PENDING = 'PENDING';
    case ARRIVED = 'ARRIVED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case READY = 'READY';
    case DELIVERED = 'DELIVERED';
    case CANCELLED = 'CANCELLED';

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::PENDING => [self::ARRIVED, self::CANCELLED],
            self::ARRIVED => [self::IN_PROGRESS, self::CANCELLED],
            self::IN_PROGRESS => [self::READY],
            self::READY => [self::DELIVERED],
            self::DELIVERED, self::CANCELLED => [],
        };
    }

    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::ARRIVED => 'arrived_at',
            self::IN_PROGRESS => 'started_at',
            self::READY => 'ready_at',
            self::DELIVERED => 'delivered_at',
            self::CANCELLED => 'cancelled_at',
            self::PENDING => null,
        };
    }

    /** Mientras no se lave, la orden se puede corregir. */
    public function isEditable(): bool
    {
        return in_array($this, [self::PENDING, self::ARRIVED], true);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::DELIVERED, self::CANCELLED], true);
    }
}
