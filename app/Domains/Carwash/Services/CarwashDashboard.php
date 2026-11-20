<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Enums\BillingStatus;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\WorkArea;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Models\WorkOrderLine;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The car wash's day (America/Guayaquil): orders by status, average wash time, vehicles ready but not
 * charged, orders waiting at the cash register, productivity per washer, the vehicles being washed in each work area
 * and the best-selling rates of the period. Amounts live in Ventas.
 */
final class CarwashDashboard
{
    /** The periods of «Tarifas más vendidas»: the day, the day before or the last 7 days. */
    public const RATE_PERIODS = ['today', 'yesterday', 'week'];

    /** @return array<string, mixed> */
    public function day(Company $company, CarbonImmutable $date, string $ratesPeriod = 'today'): array
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
            // Configuración › Insumos «Aviso de insumo bajo»: supplies at or below the low-stock threshold of Sistema.
            'lowSupplies' => CarwashSetting::for($company)->supply_alert
                ? collect(app(CarwashReports::class)->supplies($company))->where('low', true)->map(fn (array $supply) => ['name' => $supply['name'], 'stock' => $supply['stock'], 'unit' => $supply['unit']])->values()->all()
                : [],
            'washers' => app(WorkerDirectory::class)->list($company)
                ->map(fn (User $washer) => [
                    'publicId' => $washer->public_id,
                    'name' => $washer->name,
                    'inProgress' => $orders->where('washer_id', $washer->id)->where('status', WorkOrderStatus::IN_PROGRESS)->count(),
                    'finished' => $orders->where('washer_id', $washer->id)->whereIn('status', [WorkOrderStatus::READY, WorkOrderStatus::DELIVERED])->count(),
                ])->values(),
            'areas' => $this->areas($company),
            'topRates' => $this->topRates($company, $date, $ratesPeriod),
        ];
    }

    /**
     * Each active work area with the vehicles being washed in it now (any day); those washed without an area go in
     * «Sin área».
     *
     * @return list<array{publicId: ?string, name: string, capacity: int, vehicles: list<array<string, mixed>>}>
     */
    private function areas(Company $company): array
    {
        $washing = WorkOrder::query()->with(['vehicle', 'washer'])->where('company_id', $company->id)
            ->where('status', WorkOrderStatus::IN_PROGRESS)->orderBy('started_at')->get()->groupBy(fn (WorkOrder $order) => (int) $order->work_area_id);
        $vehicles = fn (int $areaId) => $washing->get($areaId, collect())->map(fn (WorkOrder $order) => [
            'orderPublicId' => $order->public_id,
            'plate' => $order->vehicle?->plate,
            'washer' => $order->washer?->name,
            'startedAt' => $order->started_at?->toIso8601String(),
            'endsAt' => $order->scheduled_end_at?->toIso8601String(),
        ])->values()->all();

        $areas = WorkArea::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get()
            ->map(fn (WorkArea $area) => ['publicId' => $area->public_id, 'name' => $area->name, 'capacity' => $area->capacity, 'vehicles' => $vehicles($area->id)])->all();
        if ($washing->has(0)) {
            $areas[] = ['publicId' => null, 'name' => 'Sin área', 'capacity' => $washing->get(0)->count(), 'vehicles' => $vehicles(0)];
        }

        return $areas;
    }

    /**
     * «Tarifas más vendidas»: how many times each rate (a service for a vehicle specification, or the service with a
     * single price) went in the orders of the period, cancelled ones aside. Times, never amounts.
     *
     * @return list<array{name: string, times: int}>
     */
    private function topRates(Company $company, CarbonImmutable $date, string $period): array
    {
        [$start, $end] = match ($period) {
            'yesterday' => [$date->subDay()->startOfDay(), $date->subDay()->endOfDay()],
            'week' => [$date->subDays(6)->startOfDay(), $date->endOfDay()],
            default => [$date->startOfDay(), $date->endOfDay()],
        };
        $orders = WorkOrder::query()->select('id')->where('company_id', $company->id)->where('status', '!=', WorkOrderStatus::CANCELLED)
            ->where(fn ($query) => $query->whereBetween('scheduled_at', [$start, $end])
                ->orWhere(fn ($walkIn) => $walkIn->whereNull('scheduled_at')->whereBetween('created_at', [$start, $end])));

        return WorkOrderLine::query()->where('company_id', $company->id)->where('kind', WorkOrderLine::SERVICE)->whereIn('work_order_id', $orders)
            ->orderBy('id')->get(['service_id', 'service_price_id', 'description'])
            ->groupBy(fn (WorkOrderLine $line) => $line->service_price_id ? "price-{$line->service_price_id}" : "service-{$line->service_id}")
            ->map(fn ($lines) => ['name' => $lines->last()->description, 'times' => $lines->count()])
            ->sortBy([['times', 'desc'], ['name', 'asc']])->take(10)->values()->all();
    }
}
