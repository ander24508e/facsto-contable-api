<?php

namespace App\Domains\FiscalDocuments\Models;

use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'electronic_document_id', 'type', 'version', 'disk', 'path', 'sha256', 'size_bytes', 'mime_type'])]
class DocumentArtifact extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $artifact) => $artifact->public_id ??= (string) Str::uuid7());
    }

    protected function casts(): array
    {
        return [
            'type' => DocumentArtifactType::class,
            'version' => 'integer',
            'size_bytes' => 'integer',
        ];
    }
}
