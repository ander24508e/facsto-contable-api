<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Models\CarwashNotification;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\NotificationOutbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de avisos de la Lavadora y «Avisar por WhatsApp». El enlace abre el chat del cliente con el mensaje escrito;
 * al pedirlo el aviso queda «Abierto» (lo envía el operador). «Omitir» lo saca de la bandeja.
 */
final class NotificationController extends CarwashController
{
    public function index(Request $request, NotificationOutbox $outbox): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:PENDING,OPENED,SKIPPED']])['status'] ?? 'PENDING';
        $items = CarwashNotification::query()->with(['workOrder.vehicle', 'workOrder.customer'])
            ->where('company_id', $this->companyId())->where('status', $status)->latest('id')->limit(100)->get();

        return response()->json(['data' => $items->map(fn (CarwashNotification $notice) => $this->present($notice, $outbox))->values()]);
    }

    /** «Avisar por WhatsApp» from the order: its «listo» aviso (or a new one) with the wa.me link. */
    public function whatsapp(Request $request, string $workOrder, NotificationOutbox $outbox): JsonResponse
    {
        $order = $this->owned(WorkOrder::class, $workOrder);
        $kind = $order->status->value === 'PENDING' ? CarwashNotification::REMINDER : CarwashNotification::READY;
        $notice = $outbox->queue($order, $kind);

        return response()->json(['data' => $this->open($notice, $request, $outbox)]);
    }

    public function update(Request $request, string $notification, NotificationOutbox $outbox): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'in:OPENED,SKIPPED']])['status'];
        $notice = $this->owned(CarwashNotification::class, $notification)->load(['workOrder.vehicle', 'workOrder.customer']);
        if ($status === 'OPENED') {
            return response()->json(['data' => $this->open($notice, $request, $outbox)]);
        }
        $notice->forceFill(['status' => 'SKIPPED'])->save();

        return response()->json(['data' => $this->present($notice, $outbox)]);
    }

    /** @return array<string, mixed> */
    private function open(CarwashNotification $notice, Request $request, NotificationOutbox $outbox): array
    {
        $notice->loadMissing(['workOrder.vehicle', 'workOrder.customer']);
        // The customer's current phone (it may have been added after the aviso was queued).
        $notice->forceFill(['recipient' => $notice->workOrder->contactPhone() ?: $notice->recipient, 'status' => 'OPENED', 'sent_at' => now(), 'sent_by' => $request->user()->id])->save();

        return $this->present($notice, $outbox);
    }

    /** @return array<string, mixed> */
    private function present(CarwashNotification $notice, NotificationOutbox $outbox): array
    {
        $order = $notice->workOrder;

        return [
            'publicId' => $notice->public_id,
            'kind' => $notice->kind,
            'status' => $notice->status,
            'recipient' => $notice->recipient,
            'message' => $notice->message,
            'whatsappUrl' => $outbox->whatsappUrl($order->contactPhone() ?: $notice->recipient, $notice->message),
            'workOrder' => ['publicId' => $order->public_id, 'code' => $order->code(), 'plate' => $order->vehicle->plate, 'customer' => $order->contactName()],
            'createdAt' => $notice->created_at?->toIso8601String(),
            'sentAt' => $notice->sent_at?->toIso8601String(),
        ];
    }
}
