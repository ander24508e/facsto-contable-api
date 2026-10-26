<?php

namespace App\Domains\Sales\Services;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\Sales\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tabs of the single Sales screen: the sale and its fiscal document seen as one lifecycle. */
final class SaleStageFilter
{
    /** @var array<string, list<DocumentStatus>> fiscal document statuses of each stage; drafts have no document */
    public const STAGES = [
        'draft' => [],
        'prepared' => [DocumentStatus::NUMBERED, DocumentStatus::XML_BUILT, DocumentStatus::SIGNED],
        'sent' => [DocumentStatus::QUEUED, DocumentStatus::RECEIVED, DocumentStatus::PENDING_AUTHORIZATION],
        'authorized' => [DocumentStatus::AUTHORIZED],
        'problems' => [DocumentStatus::RETURNED, DocumentStatus::NOT_AUTHORIZED, DocumentStatus::FAILED],
    ];

    public static function apply(Builder|HasMany $query, string $stage): void
    {
        if ($stage === 'draft') {
            $query->where('status', SaleStatus::DRAFT->value);

            return;
        }

        $statuses = array_map(fn (DocumentStatus $status): string => $status->value, self::STAGES[$stage]);
        $query->whereHas('electronicDocument', fn (Builder $document) => $document->whereIn('status', $statuses));
    }
}
