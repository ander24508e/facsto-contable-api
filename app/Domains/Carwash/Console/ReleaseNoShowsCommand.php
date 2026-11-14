<?php

namespace App\Domains\Carwash\Console;

use App\Domains\Carwash\Enums\WorkOrderSource;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\WorkOrder;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lavadora › Configuración › Agenda «Tolerancia de llegada»: a reservation still pending that many minutes after its
 * time (and without a sale) is cancelled as «No llegó», which frees its area and its slot. Registered and scheduled every
 * five minutes by ModulesServiceProvider (the core never references the module).
 */
final class ReleaseNoShowsCommand extends Command
{
    protected $signature = 'carwash:release-no-shows';

    protected $description = 'Cancela las reservas de la Lavadora cuyo cliente no llegó dentro de la tolerancia';

    public function handle(AuditRecorder $audit): int
    {
        $released = 0;
        CarwashSetting::query()->whereNotNull('arrival_tolerance_minutes')->with('company')->each(function (CarwashSetting $settings) use ($audit, &$released): void {
            $limit = now()->subMinutes($settings->arrival_tolerance_minutes);
            WorkOrder::query()->with(['creator'])
                ->where('company_id', $settings->company_id)
                ->where('status', WorkOrderStatus::PENDING)
                ->where('source', WorkOrderSource::RESERVATION)
                ->whereNull('sale_id')
                ->where('scheduled_at', '<', $limit)
                ->each(function (WorkOrder $order) use ($settings, $audit, &$released): void {
                    DB::transaction(function () use ($order, $settings, $audit): void {
                        $order->forceFill([
                            'status' => WorkOrderStatus::CANCELLED,
                            'cancelled_at' => now(),
                            'cancel_reason' => "No llegó (tolerancia de {$settings->arrival_tolerance_minutes} minutos)",
                        ])->save();
                        if ($order->creator && $settings->company) {
                            $audit->record($order->creator, $settings->company, 'carwash.work_order.no_show', $order, ['status' => WorkOrderStatus::PENDING->value], ['status' => WorkOrderStatus::CANCELLED->value]);
                        }
                    });
                    $released++;
                });
        });
        $this->info("Reservas liberadas: {$released}");

        return self::SUCCESS;
    }
}
