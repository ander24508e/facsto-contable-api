<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Enums\BillingStatus;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The car wash's day (America/Guayaquil): orders by status, average wash time, vehicles ready but not
 * charged, orders waiting at the cash register and productivity per washer. Amounts live in Ventas.
 */
final class CarwashDashboard
{
    /** @return array<string, mixed> */
    public function day(Company $company, CarbonImmutable $date): array
    {
        $start = $date->startOfDay();
        $end = $date->endOfDay();
        $orders = WorkOrder::query()
            ->with('sale.electronicDocument')
            ->where('company_id', $company->id)
            ->where(fn ($query) => $query->whereBetween('scheduled_at', [$start, $end])
                ->orWhere(fn ($walkIn) => $walkIn->whereNull('scheduled_at')->whereBetween('created_at', [$start, $end])))
            ->get();

        $byStatus = [];
        foreach (WorkOrderStatus::cases() as $status) {
            $byStatus[$status->value] = $orders->where('status', $status)->count();
        }
        $washed = $orders->filter(fn (WorkOrder $order) => $order->started_at && $order->ready_at);
        $average = $washed->isEmpty() ? null : (int) round($washed->avg(fn (WorkOrder $order) => $order->started_at->diffInMinutes($order->ready_at)));
        $billing = fn (WorkOrder $order) => BillingStatus::of($order->sale);

        return [
            'date' => $date->toDateString(),
            'total' => $orders->count(),
            'byStatus' => $byStatus,
            'averageWashMinutes' => $average,
            'readyUnbilled' => $orders->filter(fn (WorkOrder $order) => $order->status === WorkOrderStatus::READY && $billing($order) === BillingStatus::UNBILLED)->count(),
            'awaitingPayment' => $orders->filter(fn (WorkOrder $order) => $billing($order) === BillingStatus::DRAFT)->count(),
            'washers' => app(WorkerDirectory::class)->list($company)
                ->map(fn (User $washer) => [
                    'publicId' => $washer->public_id,
                    'name' => $washer->name,
                    'inProgress' => $orders->where('washer_id', $washer->id)->where('status', WorkOrderStatus::IN_PROGRESS)->count(),
                    'finished' => $orders->where('washer_id', $washer->id)->whereIn('status', [WorkOrderStatus::READY, WorkOrderStatus::DELIVERED])->count(),
                ])->values(),
        ];
    }
}
