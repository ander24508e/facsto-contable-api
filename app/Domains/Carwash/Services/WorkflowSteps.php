<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;

/**
 * The steps an order may take next with the company's Configuración › Servicios: without «Llegó» a pending order
 * starts washing directly; without «Listo» the wash goes straight to delivery. Cancelling stays as before.
 */
final class WorkflowSteps
{
    /** @return list<WorkOrderStatus> */
    public function next(WorkOrderStatus $status, CarwashSetting $settings): array
    {
        return match ($status) {
            WorkOrderStatus::PENDING => [$settings->use_arrived_step ? WorkOrderStatus::ARRIVED : WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::CANCELLED],
            WorkOrderStatus::IN_PROGRESS => [$settings->use_ready_step ? WorkOrderStatus::READY : WorkOrderStatus::DELIVERED],
            default => $status->next(),
        };
    }

    /** The step where the vehicle is received (and the checklist answered): «Llegó», or «En proceso» without it. */
    public function reception(CarwashSetting $settings): WorkOrderStatus
    {
        return $settings->use_arrived_step ? WorkOrderStatus::ARRIVED : WorkOrderStatus::IN_PROGRESS;
    }

    /** The step where the wash is done: «Listo», or «Entregado» without it. */
    public function finished(CarwashSetting $settings): WorkOrderStatus
    {
        return $settings->use_ready_step ? WorkOrderStatus::READY : WorkOrderStatus::DELIVERED;
    }
}
