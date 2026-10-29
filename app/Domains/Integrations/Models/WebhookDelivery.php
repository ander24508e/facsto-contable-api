<?php

namespace App\Domains\Integrations\Models;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'api_client_id', 'electronic_document_id', 'event', 'payload', 'status', 'attempts', 'response_status', 'last_error', 'delivered_at'])]
class WebhookDelivery extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $delivery) => $delivery->public_id ??= (string) Str::uuid7());
    }

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'response_status' => 'integer', 'delivered_at' => 'immutable_datetime'];
    }
}
