<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\AreaType;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\WorkArea;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Companies\Models\Company;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Day agenda by work areas. The general opening hours are split into slots of `slot_minutes`; each active area is
 * open inside its own hours and serves `capacity` vehicles at once. A reservation takes its area from
 * `scheduled_at` until `scheduled_end_at` (the duration of the order's services plus the cleanup minutes); cancelled
 * orders free it. Configuración › Agenda adds closed weekdays and dates, how long before a reservation is taken (minimum
 * and maximum) and how many vehicles may start in the same slot; › Áreas, one vehicle per area at a time.
 * Time zone: the application's (America/Guayaquil).
 */
final class AgendaService
{
    /**
     * @return array{areas: list<array<string, mixed>>, slots: list<array<string, mixed>>}
     */
    public function day(Company $company, CarbonInterface $date): array
    {
        $settings = CarwashSetting::for($company);
        $day = CarbonImmutable::parse($date->format('Y-m-d'), config('app.timezone'));
        $open = $day->setTimeFromTimeString((string) $settings->opening_time);
        $close = $day->setTimeFromTimeString((string) $settings->closing_time);
        $areas = WorkArea::query()->with('types')->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get();
        $orders = WorkOrder::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', WorkOrderStatus::CANCELLED)
            ->whereNotNull('work_area_id')
            ->where('scheduled_at', '<', $close)
            ->where('scheduled_end_at', '>', $open)
            ->get(['id', 'work_area_id', 'scheduled_at', 'scheduled_end_at'])
            ->groupBy('work_area_id');

        $closedDay = $this->isClosed($settings, $day);
        $slots = [];
        for ($start = $open; $start->lt($close); $start = $start->addMinutes($settings->slot_minutes)) {
            $end = $start->addMinutes($settings->slot_minutes);
            $cells = $areas->map(function (WorkArea $area) use ($day, $start, $end, $orders, $closedDay, $settings): array {
                [$areaOpen, $areaClose] = $this->hours($area, $day);
                $isOpen = ! $closedDay && $start->gte($areaOpen) && $end->lte($areaClose);
                $booked = $this->peak($orders->get($area->id, collect()), $start, $end);
                $capacity = $this->capacity($area, $settings);

                return [
                    'areaPublicId' => $area->public_id,
                    'open' => $isOpen,
                    'capacity' => $isOpen ? $capacity : 0,
                    'booked' => $booked,
                    'available' => $isOpen ? max(0, $capacity - $booked) : 0,
                ];
            })->values();
            $available = (int) $cells->sum('available');
            if ($settings->vehicles_per_slot !== null) {
                $starting = $orders->collapse()->filter(fn (WorkOrder $order) => $order->scheduled_at->gte($start) && $order->scheduled_at->lt($end))->count();
                $available = min($available, max(0, $settings->vehicles_per_slot - $starting));
            }
            $slots[] = [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'capacity' => (int) $cells->sum('capacity'),
                'booked' => (int) $cells->sum('booked'),
                'available' => $available,
                'areas' => $cells->all(),
            ];
        }

        return [
            'closed' => $closedDay,
            'areas' => $areas->map(fn (WorkArea $area): array => [
                'publicId' => $area->public_id,
                'name' => $area->name,
                'types' => $area->types->map(fn (AreaType $type): array => ['publicId' => $type->public_id, 'name' => $type->name])->values()->all(),
                'capacity' => $this->capacity($area, $settings),
                'opensAt' => substr((string) $area->opens_at, 0, 5),
                'closesAt' => substr((string) $area->closes_at, 0, 5),
            ])->values()->all(),
            'slots' => $slots,
        ];
    }

    /**
     * A reservation must fit the general hours and the area's, the area must do every area type the order needs and it
     * must have room during the whole stay. Returns when the area is free again.
     *
     * A reservation (not a walk-in taking an area now) must also respect the closed days, the minimum and maximum
     * advance and the vehicles per slot.
     *
     * @param  list<int>  $typeIds  the area types of the order's services
     */
    public function assertAvailable(Company $company, WorkArea $area, CarbonInterface $at, int $minutes, array $typeIds, ?WorkOrder $ignore = null, bool $reservation = true): CarbonImmutable
    {
        if (! $area->is_active) {
            throw new ApiException(ApiErrorCode::CARWASH_WORK_AREA_NOT_FOUND, 'El área de trabajo no existe o está inactiva.', 422, ['errors' => ['workAreaPublicId' => ['El área de trabajo está inactiva.']]]);
        }
        $missing = array_values(array_diff($typeIds, $area->types()->pluck('carwash_area_types.id')->all()));
        if ($missing !== []) {
            $jobs = mb_strtolower(implode(' y ', AreaType::query()->whereIn('id', $missing)->ordered()->pluck('name')->all()));
            throw new ApiException(ApiErrorCode::CARWASH_WORK_AREA_TYPE_MISMATCH, "El área «{$area->name}» no hace {$jobs}.", 422, ['errors' => ['workAreaPublicId' => ["El área «{$area->name}» no hace {$jobs}."]]]);
        }

        $settings = CarwashSetting::for($company);
        $start = CarbonImmutable::instance($at)->setTimezone(config('app.timezone'))->startOfMinute();
        // The area stays busy for the services plus the cleanup before the next vehicle.
        $end = $start->addMinutes(max(1, $minutes) + $settings->cleanup_minutes);
        $day = $start->startOfDay();
        if ($reservation) {
            $this->assertReservable($settings, $start, $company, $ignore);
        }
        [$areaOpen, $areaClose] = $this->hours($area, $day);
        $open = $day->setTimeFromTimeString((string) $settings->opening_time)->max($areaOpen);
        $close = $day->setTimeFromTimeString((string) $settings->closing_time)->min($areaClose);
        if ($start->lt($open) || $end->gt($close)) {
            $message = "La reserva ({$start->format('H:i')}–{$end->format('H:i')}) queda fuera del horario de «{$area->name}» ({$open->format('H:i')}–{$close->format('H:i')}).";
            throw new ApiException(ApiErrorCode::CARWASH_AGENDA_OUTSIDE_HOURS, $message, 422, ['errors' => ['scheduledAt' => [$message]]]);
        }

        $overlapping = WorkOrder::query()
            ->where('company_id', $company->id)
            ->where('work_area_id', $area->id)
            ->where('status', '!=', WorkOrderStatus::CANCELLED)
            ->where('scheduled_at', '<', $end)
            ->where('scheduled_end_at', '>', $start)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->get(['id', 'scheduled_at', 'scheduled_end_at']);
        if ($this->peak($overlapping, $start, $end) >= $this->capacity($area, $settings)) {
            throw new ApiException(ApiErrorCode::CARWASH_AGENDA_SLOT_FULL, "«{$area->name}» está ocupada a esa hora. Elige otra hora u otra área.", 409, [
                'errors' => ['scheduledAt' => ["«{$area->name}» está ocupada a esa hora."]],
            ]);
        }

        return $end;
    }

    /** The area of the company by its public id (inactive ones are rejected when reserving). */
    public function area(Company $company, ?string $publicId): WorkArea
    {
        if (! $publicId) {
            throw new ApiException(ApiErrorCode::CARWASH_WORK_AREA_NOT_FOUND, 'Elige el área de trabajo de la reserva.', 422, ['errors' => ['workAreaPublicId' => ['Elige el área de trabajo de la reserva.']]]);
        }

        return WorkArea::query()->where('company_id', $company->id)->where('public_id', $publicId)->first()
            ?? throw new ApiException(ApiErrorCode::CARWASH_WORK_AREA_NOT_FOUND, 'El área de trabajo no existe o está inactiva.', 422, ['errors' => ['workAreaPublicId' => ['El área de trabajo no existe.']]]);
    }

    /**
     * Takes the area for the order's lines: it stays busy for the sum of their durations and must do the area types
     * of their services. Returns when it is free again.
     *
     * @param  list<array{service_id: ?int, duration_minutes: int}>  $lines
     */
    public function reserve(Company $company, WorkArea $area, CarbonInterface $at, array $lines, ?WorkOrder $ignore = null, bool $reservation = true): CarbonImmutable
    {
        [$minutes, $types] = $this->needs($lines);

        return $this->assertAvailable($company, $area, $at, $minutes, $types, $ignore, $reservation);
    }

    /**
     * Configuración › Áreas «Asignar el área libre»: the first active area (by name) that does the order's work and
     * has room at that time. Null when none is free.
     *
     * @param  list<array{service_id: ?int, duration_minutes: int}>  $lines
     */
    public function freeArea(Company $company, CarbonInterface $at, array $lines, ?WorkOrder $ignore = null, bool $reservation = true): ?WorkArea
    {
        [$minutes, $types] = $this->needs($lines);
        $failure = null;
        foreach (WorkArea::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get() as $area) {
            try {
                $this->assertAvailable($company, $area, $at, $minutes, $types, $ignore, $reservation);

                return $area;
            } catch (ApiException $exception) {
                // A rule of the whole agenda (closed day, advance, slot full) fails the same in every area.
                if (in_array($exception->errorCode, [ApiErrorCode::CARWASH_AGENDA_CLOSED, ApiErrorCode::CARWASH_AGENDA_ADVANCE], true)) {
                    throw $exception;
                }
                $failure = $exception;
            }
        }
        if ($failure !== null && $failure->errorCode === ApiErrorCode::CARWASH_AGENDA_SLOT_FULL) {
            throw new ApiException(ApiErrorCode::CARWASH_AGENDA_SLOT_FULL, 'No hay un área libre a esa hora. Elige otra hora.', 409, ['errors' => ['scheduledAt' => ['No hay un área libre a esa hora.']]]);
        }

        return null;
    }

    /**
     * @param  list<array{service_id: ?int, duration_minutes: int}>  $lines
     * @return array{0: int, 1: list<int>} minutes and area types the order needs
     */
    private function needs(array $lines): array
    {
        $minutes = (int) array_sum(array_column($lines, 'duration_minutes'));
        $types = Service::query()->whereIn('id', array_filter(array_column($lines, 'service_id')))->whereNotNull('area_type_id')
            ->distinct()->pluck('area_type_id')->map(fn ($id) => (int) $id)->values()->all();

        return [$minutes, $types];
    }

    /** Closed weekdays and dates, the advance window and the vehicles that may start in one slot. */
    private function assertReservable(CarwashSetting $settings, CarbonImmutable $start, Company $company, ?WorkOrder $ignore): void
    {
        if ($this->isClosed($settings, $start)) {
            $message = 'La Lavadora no atiende el '.$start->locale('es')->isoFormat('dddd D [de] MMMM').'.';
            throw new ApiException(ApiErrorCode::CARWASH_AGENDA_CLOSED, $message, 422, ['errors' => ['scheduledAt' => [$message]]]);
        }
        $now = CarbonImmutable::now(config('app.timezone'));
        if ($settings->min_advance_minutes > 0 && $start->lt($now->addMinutes($settings->min_advance_minutes))) {
            $message = "Se reserva con al menos {$settings->min_advance_minutes} minutos de anticipación.";
            throw new ApiException(ApiErrorCode::CARWASH_AGENDA_ADVANCE, $message, 422, ['errors' => ['scheduledAt' => [$message]]]);
        }
        if ($start->gt($now->addDays($settings->max_advance_days)->endOfDay())) {
            $message = "Se reserva con hasta {$settings->max_advance_days} días de anticipación.";
            throw new ApiException(ApiErrorCode::CARWASH_AGENDA_ADVANCE, $message, 422, ['errors' => ['scheduledAt' => [$message]]]);
        }
        if ($settings->vehicles_per_slot !== null) {
            $day = $start->startOfDay();
            $open = $day->setTimeFromTimeString((string) $settings->opening_time);
            $slot = $open->addMinutes((int) (floor($open->diffInMinutes($start, false) / $settings->slot_minutes) * $settings->slot_minutes));
            $starting = WorkOrder::query()->where('company_id', $company->id)->where('status', '!=', WorkOrderStatus::CANCELLED)
                ->where('scheduled_at', '>=', $slot)->where('scheduled_at', '<', $slot->addMinutes($settings->slot_minutes))
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))->count();
            if ($starting >= $settings->vehicles_per_slot) {
                $message = "Ya hay {$settings->vehicles_per_slot} vehículos en ese turno. Elige otra hora.";
                throw new ApiException(ApiErrorCode::CARWASH_AGENDA_SLOT_FULL, $message, 409, ['errors' => ['scheduledAt' => [$message]]]);
            }
        }
    }

    private function isClosed(CarwashSetting $settings, CarbonImmutable $day): bool
    {
        return in_array($day->isoWeekday(), $settings->closedWeekdays(), true) || in_array($day->toDateString(), $settings->closedDates(), true);
    }

    /** Configuración › Áreas «Un vehículo por área»: every area serves one at a time. */
    private function capacity(WorkArea $area, CarwashSetting $settings): int
    {
        return $settings->one_vehicle_per_area ? min(1, (int) $area->capacity) : (int) $area->capacity;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the area's hours on that day */
    private function hours(WorkArea $area, CarbonImmutable $day): array
    {
        return [$day->setTimeFromTimeString((string) $area->opens_at), $day->setTimeFromTimeString((string) $area->closes_at)];
    }

    /**
     * Most orders in the area at the same moment between $from and $to. The peak of overlapping stays is always at
     * $from or at the start of one of them.
     *
     * @param  Collection<int, WorkOrder>  $orders
     */
    private function peak(Collection $orders, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $stays = $orders->filter(fn (WorkOrder $order) => $order->scheduled_at->lt($to) && $order->scheduled_end_at->gt($from));
        $moments = $stays->map(fn (WorkOrder $order) => $order->scheduled_at->max($from))->push($from);

        return (int) $moments->map(fn ($moment) => $stays->filter(fn (WorkOrder $order) => $order->scheduled_at->lte($moment) && $order->scheduled_end_at->gt($moment))->count())->max();
    }
}
