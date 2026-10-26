<?php

namespace App\Domains\Sri\Models;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\Enums\SriOperation;
use App\Domains\Sri\Enums\SriSubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'electronic_document_id', 'operation', 'attempt', 'endpoint_environment', 'status', 'request_sha256', 'http_status', 'started_at', 'finished_at', 'error_code'])]
class SriSubmission extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $submission) => $submission->public_id ??= (string) Str::uuid7());
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SriMessage::class, 'submission_id');
    }

    protected function casts(): array
    {
        return [
            'operation' => SriOperation::class,
            'status' => SriSubmissionStatus::class,
            'attempt' => 'integer',
            'http_status' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
