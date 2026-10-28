<?php

namespace App\Domains\FiscalDocuments\Services;

use App\Domains\Companies\Models\EmissionPoint;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\DocumentSequence;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;

/** Invoice numbering of one emission point for the current company RUC. */
final class DocumentSequenceStatus
{
    /** @return array{issuerRuc: string|null, initialSequential: int, nextSequential: int, locked: bool} */
    public function forPoint(EmissionPoint $point, ?string $issuerRuc): array
    {
        if ($issuerRuc === null) {
            return ['issuerRuc' => null, 'initialSequential' => 1, 'nextSequential' => 1, 'locked' => false];
        }
        $sequence = DocumentSequence::query()
            ->where('company_id', $point->company_id)
            ->where('emission_point_id', $point->id)
            ->where('document_type', DocumentType::INVOICE->value)
            ->where('issuer_ruc', $issuerRuc)
            ->first();

        return [
            'issuerRuc' => $issuerRuc,
            'initialSequential' => $sequence?->initial_number ?? 1,
            'nextSequential' => ($sequence?->current_number ?? 0) + 1,
            'locked' => $this->hasDocuments($point, $issuerRuc),
        ];
    }

    /** Once this RUC numbered a document in the point, its numbering can never be redefined. */
    public function hasDocuments(EmissionPoint $point, string $issuerRuc): bool
    {
        return ElectronicDocument::query()
            ->where('company_id', $point->company_id)
            ->where('emission_point_id', $point->id)
            ->where('document_type', DocumentType::INVOICE->value)
            ->where('issuer_ruc', $issuerRuc)
            ->exists();
    }
}
