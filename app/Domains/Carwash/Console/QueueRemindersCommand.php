<?php

namespace App\Domains\Carwash\Console;

use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashNotification;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\NotificationOutbox;
use Illuminate\Console\Command;

/**
 * Lavadora › Configuración › Clientes «Recordatorio de cita»: deja en la bandeja el recordatorio de cada reserva pendiente
 * que empieza dentro de las horas configuradas (una vez por reserva). Corre cada 15 minutos (ModulesServiceProvider).
 */
final class QueueRemindersCommand extends Command
{
    protected $signature = 'carwash:queue-reminders';

    protected $description = 'Deja en la bandeja los recordatorios de las reservas próximas de la Lavadora';

    public function handle(NotificationOutbox $outbox): int
    {
        $queued = 0;
        CarwashSetting::query()->where('notify_reminder', true)->get()->each(function (CarwashSetting $settings) use ($outbox, &$queued): void {
            WorkOrder::query()
                ->where('company_id', $settings->company_id)
                ->where('status', WorkOrderStatus::PENDING)
                ->where('source', 'RESERVATION')
                ->whereBetween('scheduled_at', [now(), now()->addHours(max(1, (int) $settings->reminder_hours))])
                ->whereDoesntHave('notifications', fn ($notice) => $notice->where('kind', CarwashNotification::REMINDER))
                ->each(function (WorkOrder $order) use ($outbox, &$queued): void {
                    $outbox->queue($order, CarwashNotification::REMINDER);
                    $queued++;
                });
        });
        $this->info("Recordatorios en la bandeja: {$queued}");

        return self::SUCCESS;
    }
}
