<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Companies\Models\Company;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Day agenda: opening hours split into slots of `slot_minutes`; each slot takes `vehicles_per_slot`
 * scheduled orders (cancelled ones do not count). Time zone: the application's (America/Guayaquil).
 */
final class AgendaService
{
    /** @return list<array{start: string, end: string, capacity: int, booked: int, available: int}> */
    public function day(Company $company, CarbonInterface $date): array
    {
        $settings = CarwashSetting::for($company);
        $day = CarbonImmutable::parse($date->format('Y-m-d'), config('app.timezone'));
        $open = $day->setTimeFromTimeString((string) $settings->opening_time);
        $close = $day->setTimeFromTimeString((string) $settings->closing_time);
        $orders = WorkOrder::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', WorkOrderStatus::CANCELLED)
            ->whereBetween('scheduled_at', [$open, $close])
            ->pluck('scheduled_at');

        $slots = [];
        for ($start = $open; $start->lt($close); $start = $start->addMinutes($settings->slot_minutes)) {
            $end = $start->addMinutes($settings->slot_minutes);
            $booked = $orders->filter(fn ($at) => $at !== null && $at->gte($start) && $at->lt($end))->count();
            $slots[] = [
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'capacity' => $settings->vehicles_per_slot,
                'booked' => $booked,
                'available' => max(0, $settings->vehicles_per_slot - $booked),
            ];
        }

        return $slots;
    }

    /** A scheduled order must fall within opening hours and in a slot with room. */
    public function assertAvailable(Company $company, CarbonInterface $at, ?WorkOrder $ignore = null): void
    {
        $settings = CarwashSetting::for($company);
        $local = CarbonImmutable::instance($at)->setTimezone(config('app.timezone'));
        $open = $local->setTimeFromTimeString((string) $settings->opening_time);
        $close = $local->setTimeFromTimeString((string) $settings->closing_time);
        if ($local->lt($open) || $local->gte($close)) {
            throw new ApiException(ApiErrorCode::CARWASH_AGENDA_OUTSIDE_HOURS, 'La hora está fuera del horario de atención.', 422, ['errors' => ['scheduledAt' => ['La hora está fuera del horario de atención.']]]);
        }
        $slotStart = $open->addMinutes(intdiv((int) $open->diffInMinutes($local), $settings->slot_minutes) * $settings->slot_minutes);
        $booked = WorkOrder::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', WorkOrderStatus::CANCELLED)
            ->where('scheduled_at', '>=', $slotStart)
            ->where('scheduled_at', '<', $slotStart->addMinutes($settings->slot_minutes))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->count();
        if ($booked >= $settings->vehicles_per_slot) {
            throw new ApiException(ApiErrorCode::CARWASH_AGENDA_SLOT_FULL, 'Ese turno ya está completo. Elige otra hora.', 409, ['slotStart' => $slotStart->toIso8601String()]);
        }
    }
}
