<?php

namespace App\Domains\FiscalDocuments\Services;

use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DocumentSequenceAllocator
{
    public function next(int $companyId, int $establishmentId, int $emissionPointId, DocumentType $type): int
    {
        $attributes = [
            'company_id' => $companyId,
            'establishment_id' => $establishmentId,
            'emission_point_id' => $emissionPointId,
            'document_type' => $type->value,
        ];
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('INSERT INTO document_sequences (company_id, establishment_id, emission_point_id, document_type, current_number, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 0, true, now(), now()) ON CONFLICT (company_id, establishment_id, emission_point_id, document_type) DO NOTHING', array_values($attributes));
        } else {
            DocumentSequence::query()->firstOrCreate($attributes, ['current_number' => 0, 'is_active' => true]);
        }

        $sequence = DocumentSequence::query()->where($attributes)->lockForUpdate()->firstOrFail();
        if (! $sequence->is_active || $sequence->current_number >= 999999999) {
            throw new RuntimeException('DOCUMENT_SEQUENCE_EXHAUSTED');
        }
        $sequence->increment('current_number');

        return (int) $sequence->current_number;
    }
}
