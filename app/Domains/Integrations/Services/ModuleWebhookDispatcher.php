<?php

namespace App\Domains\Integrations\Services;

use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Integrations\Jobs\SendModuleWebhookJob;
use App\Domains\Integrations\Models\ApiClient;
use App\Domains\Integrations\Models\WebhookDelivery;

/**
 * When a document sent by a module reaches a final SRI answer, the module is told so it can mark its order
 * as invoiced (or fix it) without polling. Credit notes notify the module of the invoice they modify.
 */
final class ModuleWebhookDispatcher
{
    private const EVENTS = [
        'AUTHORIZED' => 'authorized',
        'RETURNED' => 'returned',
        'NOT_AUTHORIZED' => 'not_authorized',
    ];

    public function documentChanged(ElectronicDocument $document): void
    {
        $event = self::EVENTS[$document->status->value] ?? null;
        $client = $event ? $this->client($document) : null;
        if (! $client) {
            return;
        }
        $sale = $document->sale ?? $document->modifiedDocument?->sale;

        $delivery = WebhookDelivery::query()->create([
            'company_id' => $document->company_id,
            'api_client_id' => $client->id,
            'electronic_document_id' => $document->id,
            'event' => ($document->modified_document_id ? 'credit_note.' : 'invoice.').$event,
            'status' => 'PENDING',
            'payload' => [
                'publicId' => $document->public_id,
                'documentType' => $document->document_type->value,
                'fullNumber' => $document->full_number,
                'accessKey' => $document->access_key,
                'status' => $document->status->value,
                'authorizationNumber' => $document->authorization_number,
                'authorizedAt' => $document->authorized_at?->toIso8601String(),
                'total' => (string) $document->total,
                'salePublicId' => $sale?->public_id,
                'sourceReference' => $sale?->source_reference,
                'modifiedDocumentPublicId' => $document->modifiedDocument?->public_id,
            ],
        ]);
        SendModuleWebhookJob::dispatch($delivery->id)->afterCommit();
    }

    private function client(ElectronicDocument $document): ?ApiClient
    {
        $document->loadMissing(['sale.apiClient', 'modifiedDocument.sale.apiClient']);
        $client = $document->sale?->apiClient ?? $document->modifiedDocument?->sale?->apiClient;

        return $client?->is_active && filled($client->webhook_url) ? $client : null;
    }

    public static function notifies(DocumentStatus $status): bool
    {
        return isset(self::EVENTS[$status->value]);
    }
}
