<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Services\AgendaService;
use App\Domains\Carwash\Services\CarwashDashboard;
use App\Domains\Companies\Context\CompanyContext;
use App\Shared\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The car wash's day, the agenda by slots and the opening hours. */
final class OperationsController extends CarwashController
{
    public function dashboard(Request $request, CompanyContext $context, CarwashDashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->day($context->company(), $this->date($request))]);
    }

    public function agenda(Request $request, CompanyContext $context, AgendaService $agenda): JsonResponse
    {
        $date = $this->date($request);

        return response()->json(['data' => ['date' => $date->toDateString(), 'slots' => $agenda->day($context->company(), $date)]]);
    }

    public function settings(CompanyContext $context): JsonResponse
    {
        return response()->json(['data' => $this->presentSettings(CarwashSetting::for($context->company()))]);
    }

    public function updateSettings(Request $request, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $data = $request->validate([
            'openingTime' => ['required', 'date_format:H:i'],
            'closingTime' => ['required', 'date_format:H:i'],
            'slotMinutes' => ['required', 'integer', 'in:15,20,30,45,60,90,120'],
            'vehiclesPerSlot' => ['required', 'integer', 'min:1', 'max:50'],
        ]);
        if ($data['closingTime'] <= $data['openingTime']) {
            throw ValidationException::withMessages(['closingTime' => 'La hora de cierre debe ser posterior a la de apertura.']);
        }
        $settings = CarwashSetting::for($context->company());
        $before = $this->presentSettings($settings);
        $settings->fill([
            'opening_time' => $data['openingTime'],
            'closing_time' => $data['closingTime'],
            'slot_minutes' => $data['slotMinutes'],
            'vehicles_per_slot' => $data['vehiclesPerSlot'],
        ])->save();
        $audit->record($request->user(), $context->company(), 'carwash.settings.updated', $context->company(), $before, $this->presentSettings($settings));

        return response()->json(['data' => $this->presentSettings($settings)]);
    }

    private function date(Request $request): CarbonImmutable
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return isset($data['date']) ? CarbonImmutable::parse($data['date'], config('app.timezone')) : CarbonImmutable::now(config('app.timezone'));
    }

    /** @return array{openingTime: string, closingTime: string, slotMinutes: int, vehiclesPerSlot: int} */
    private function presentSettings(CarwashSetting $settings): array
    {
        return [
            'openingTime' => substr((string) $settings->opening_time, 0, 5),
            'closingTime' => substr((string) $settings->closing_time, 0, 5),
            'slotMinutes' => (int) $settings->slot_minutes,
            'vehiclesPerSlot' => (int) $settings->vehicles_per_slot,
        ];
    }
}
