<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Models\CarwashNotification;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Models\WorkOrderLine;
use App\Domains\Companies\Models\Company;
use Carbon\CarbonInterface;

/**
 * Bandeja de avisos de la Lavadora. Configuración › Clientes «Avisar por WhatsApp al terminar el lavado» y «Recordatorio de
 * cita» dejan aquí el aviso con su mensaje; «Avisar por WhatsApp» abre el chat del cliente con ese mensaje escrito
 * (enlace wa.me: lo envía el operador desde su WhatsApp, sin servicios no oficiales). El envío automático por la API
 * oficial de WhatsApp o por correo tomará estos mismos avisos.
 */
final class NotificationOutbox
{
    public function queue(WorkOrder $order, string $kind): CarwashNotification
    {
        $order->loadMissing(['customer', 'vehicle', 'sale']);

        return CarwashNotification::query()->firstOrCreate(
            ['work_order_id' => $order->id, 'kind' => $kind],
            ['company_id' => $order->company_id, 'channel' => 'WHATSAPP', 'recipient' => $order->customer->phone, 'message' => $this->message($order, $kind)],
        );
    }

    public function message(WorkOrder $order, string $kind): string
    {
        $order->loadMissing(['customer', 'vehicle', 'sale', 'lines']);
        $company = Company::query()->find($order->company_id);
        $business = $company?->commercial_name ?: $company?->legal_name ?: 'la Lavadora';
        $name = mb_convert_case(mb_strtolower(trim((string) $order->customer->legal_name)), MB_CASE_TITLE);
        $plate = $order->vehicle->plate;

        return match ($kind) {
            // Short on purpose: who, which service, which vehicle.
            CarwashNotification::READY => "Hola {$name}, su orden {$this->services($order)} de su vehículo {$plate} está culminada. ¡Gracias por preferirnos!",
            default => "Hola {$name}, le recordamos su cita en {$business} el ".$this->when($order->scheduled_at)." para el vehículo {$plate}.",
        };
    }

    /** wa.me link with the message; Ecuadorian numbers (09…, 593…) are written with the country code. */
    public function whatsappUrl(?string $phone, string $message): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '593'.substr($digits, 1);
        }
        if (strlen($digits) < 10) {
            return null;
        }

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }

    /** «Lavada Completa», «Lavada y Encerado» or «Lavada, Pulido y Encerado» (the services, not the surcharges). */
    private function services(WorkOrder $order): string
    {
        $names = $order->lines->where('kind', WorkOrderLine::SERVICE)->pluck('description')->unique()->values()->all();
        if (count($names) < 2) {
            return $names[0] ?? 'de lavado';
        }
        $last = array_pop($names);

        return implode(', ', $names).' y '.$last;
    }

    private function when(?CarbonInterface $at): string
    {
        return $at ? $at->copy()->setTimezone(config('app.timezone'))->locale('es')->isoFormat('dddd D [de] MMMM [a las] HH:mm') : '';
    }
}
