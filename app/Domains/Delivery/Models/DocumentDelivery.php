<?php

namespace App\Domains\Delivery\Models;

use App\Domains\Delivery\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'electronic_document_id', 'channel', 'recipient', 'status', 'attempts', 'last_error_code', 'sent_at'])]
class DocumentDelivery extends Model
{
    public const CHANNEL_EMAIL = 'EMAIL';

    protected static function booted(): void
    {
        static::creating(fn (self $delivery) => $delivery->public_id ??= (string) Str::uuid7());
    }

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
