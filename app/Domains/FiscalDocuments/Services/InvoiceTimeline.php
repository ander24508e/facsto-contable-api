<?php

namespace App\Domains\FiscalDocuments\Services;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use Illuminate\Support\Facades\DB;

/**
 * The fiscal life of an invoice in order: each stage with its real moment, every SRI attempt with its
 * official messages, deliveries and the user actions (correction, resend) recorded in the audit log.
 */
final class InvoiceTimeline
{
    private const AUDITED = ['invoice.correction_started', 'invoice.corrected', 'invoice.resent'];

    /** @return list<array{type: string, at: string, status: ?string, detail: ?string, messages: list<array{identifier: ?string, message: string, additionalInformation: ?string}>}> */
    public function for(ElectronicDocument $invoice): array
    {
        $invoice->loadMissing(['submissions.messages', 'deliveries']);
        $events = [];
        foreach (['numbered_at' => 'NUMBERED', 'xml_built_at' => 'XML_BUILT', 'signed_at' => 'SIGNED', 'authorized_at' => 'AUTHORIZED'] as $column => $type) {
            if ($invoice->{$column} !== null) {
                $events[] = $this->event($type, $invoice->{$column}, null, $type === 'AUTHORIZED' ? $invoice->authorization_number : null);
            }
        }
        foreach ($invoice->submissions as $submission) {
            $events[] = $this->event('SRI_'.$submission->operation->value, $submission->started_at, $submission->status->value, $submission->error_code, $submission->messages->map(fn ($message): array => [
                'identifier' => $message->identifier,
                'message' => $message->message,
                'additionalInformation' => $message->additional_information,
            ])->values()->all());
        }
        foreach ($invoice->deliveries as $delivery) {
            $events[] = $this->event('DELIVERY_'.$delivery->channel, $delivery->sent_at ?? $delivery->updated_at, $delivery->status->value, $delivery->recipient);
        }
        $audits = DB::table('audit_logs')
            ->where('company_id', $invoice->company_id)
            ->where('resource_public_id', $invoice->public_id)
            ->whereIn('action', self::AUDITED)
            ->get(['action', 'after', 'created_at']);
        foreach ($audits as $audit) {
            $after = json_decode((string) $audit->after, true) ?: [];
            $detail = match ($audit->action) {
                'invoice.resent' => implode(', ', $after['recipients'] ?? []),
                'invoice.corrected' => $after['accessKey'] ?? null,
                default => null,
            };
            $events[] = $this->event(strtoupper(str_replace('invoice.', '', $audit->action)), $audit->created_at, null, $detail);
        }

        // The SRI clock is not FACSTO's: the official authorization moment never shows before the attempts
        // that obtained it. Ties keep the stage order (stable sort).
        $lastAttempt = collect($events)->filter(fn (array $event): bool => str_starts_with($event['type'], 'SRI_'))->max('at');
        $key = fn (array $event): string => $event['type'] === 'AUTHORIZED' && $lastAttempt !== null ? max($event['at'], $lastAttempt) : $event['at'];
        usort($events, fn (array $a, array $b): int => strcmp($key($a), $key($b)));

        return $events;
    }

    private function event(string $type, mixed $at, ?string $status, ?string $detail, array $messages = []): array
    {
        $moment = $at instanceof \DateTimeInterface ? $at : new \DateTimeImmutable((string) $at);

        return [
            'type' => $type,
            'at' => \DateTimeImmutable::createFromInterface($moment)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'status' => $status,
            'detail' => $detail,
            'messages' => $messages,
        ];
    }
}
