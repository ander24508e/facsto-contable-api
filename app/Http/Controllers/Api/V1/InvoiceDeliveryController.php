<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Delivery\Jobs\DeliverAuthorizedInvoiceJob;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Services\InvoiceTimeline;
use App\Http\Controllers\Controller;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** What happens with an invoice after it exists: its fiscal timeline, resending it and sharing its RIDE. */
final class InvoiceDeliveryController extends Controller
{
    public function timeline(ElectronicDocument $invoice, InvoiceTimeline $timeline): JsonResponse
    {
        $this->authorize('view', $invoice);

        return response()->json(['data' => $timeline->for($invoice)]);
    }

    /** POST /invoices/{invoice}/deliveries — resend the authorized invoice, to the buyer or to other emails. */
    public function resend(Request $request, ElectronicDocument $invoice, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('process', $invoice);
        $data = $request->validate([
            'emails' => ['sometimes', 'array', 'min:1', 'max:5'],
            'emails.*' => ['email', 'max:254', 'distinct'],
        ]);
        $this->assertAuthorized($invoice);

        $recipients = array_values(array_map('mb_strtolower', $data['emails'] ?? array_filter([$invoice->customer_email])));
        if ($recipients === []) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Escribe al menos un correo para enviar la factura.', 422);
        }
        DeliverAuthorizedInvoiceJob::dispatch($invoice->id, $recipients);
        $audit->record($request->user(), $invoice->company, 'invoice.resent', $invoice, null, ['recipients' => $recipients]);

        return response()->json(['data' => ['recipients' => $recipients]], 202);
    }

    /** POST /invoices/{invoice}/share-link — a signed, temporary link to the RIDE (for WhatsApp or any chat). */
    public function shareLink(Request $request, ElectronicDocument $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);
        $this->assertAuthorized($invoice);
        $expiresAt = now()->addDays((int) config('sri.delivery.share_link_days', 7));

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('public.ride', $expiresAt, ['invoice' => $invoice->public_id]),
            'expiresAt' => $expiresAt->toIso8601String(),
        ]]);
    }

    private function assertAuthorized(ElectronicDocument $invoice): void
    {
        if ($invoice->status !== DocumentStatus::AUTHORIZED) {
            throw new ApiException(ApiErrorCode::DOCUMENT_ARTIFACT_NOT_FOUND, 'Sólo una factura autorizada por el SRI se puede enviar o compartir.', 409);
        }
    }
}
