<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\WorkOrderSource;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\Vehicle;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\AgendaService;
use App\Domains\Carwash\Services\WasherAssigner;
use App\Domains\Carwash\Services\WorkOrderLineResolver;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opens a work order for a vehicle with its services. Numbering is per company and assigned while the company row is
 * locked: it never repeats nor skips, starts at Configuración › Órdenes «Número inicial» and keeps the prefix it was
 * born with. The customer is the vehicle's. Configuración may pick the washer (rotation), the free work area and add
 * the surcharges (extreme dirt, peak hours).
 */
final class CreateWorkOrderAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly WorkOrderLineResolver $lines,
        private readonly AgendaService $agenda,
        private readonly AuditRecorder $audit,
        private readonly WasherAssigner $washers,
    ) {}

    /** @param array<string, mixed> $data vehiclePublicId, servicePublicIds, washerPublicId, scheduledAt, workAreaPublicId, notes, dirty */
    public function execute(array $data, User $actor): WorkOrder
    {
        $company = $this->context->company();
        $settings = CarwashSetting::for($company);
        $vehicle = Vehicle::query()->where('company_id', $company->id)->where('public_id', $data['vehiclePublicId'])->first()
            ?? throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_NOT_FOUND, 'El vehículo no existe.', 404);
        if (! $vehicle->is_active) {
            throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_INACTIVE, 'El vehículo está inactivo.', 422);
        }
        $dirty = (bool) ($data['dirty'] ?? false);
        $scheduledAt = empty($data['scheduledAt']) ? null : CarbonImmutable::parse($data['scheduledAt'])->setTimezone(config('app.timezone'));
        $reserved = $scheduledAt !== null;
        $lines = [...$this->lines->resolve($vehicle, $data['servicePublicIds']), ...$this->lines->surcharges($company->id, $dirty, $scheduledAt)];

        return DB::transaction(function () use ($company, $settings, $vehicle, $lines, $scheduledAt, $reserved, $data, $actor, $dirty): WorkOrder {
            Company::query()->whereKey($company->id)->lockForUpdate()->first();
            $washer = $this->washers->assign($company, $data['washerPublicId'] ?? null, rotate: true);
            // A reservation takes a work area at its time. A walk-in with an area takes it from now; without one, none
            // unless «Asignar el área libre» picks it.
            $at = $scheduledAt ?? CarbonImmutable::now(config('app.timezone'))->startOfMinute();
            if (! empty($data['workAreaPublicId'])) {
                $area = $this->agenda->area($company, $data['workAreaPublicId']);
            } elseif ($settings->auto_assign_area) {
                $area = $this->agenda->freeArea($company, $at, $lines, null, $reserved);
                if (! $area && $reserved) {
                    throw new ApiException(ApiErrorCode::CARWASH_AGENDA_SLOT_FULL, 'No hay un área libre a esa hora. Elige otra hora.', 409, ['errors' => ['scheduledAt' => ['No hay un área libre a esa hora.']]]);
                }
            } else {
                $area = $reserved ? $this->agenda->area($company, null) : null;
            }
            $scheduledAt = $area ? $at : $scheduledAt;
            $scheduledEnd = $area ? $this->agenda->reserve($company, $area, $scheduledAt, $lines, null, $reserved) : null;
            $number = max((int) WorkOrder::query()->where('company_id', $company->id)->max('number') + 1, (int) $settings->order_start_number);

            $order = WorkOrder::query()->create([
                'company_id' => $company->id,
                'number' => $number,
                'code_prefix' => $settings->order_prefix ?: 'OT',
                'source' => $reserved ? WorkOrderSource::RESERVATION : WorkOrderSource::COUNTER,
                'vehicle_id' => $vehicle->id,
                'customer_id' => $vehicle->customer_id,
                'washer_id' => $washer?->id,
                'status' => WorkOrderStatus::PENDING,
                'scheduled_at' => $scheduledAt,
                'work_area_id' => $area?->id,
                'scheduled_end_at' => $scheduledEnd,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'dirty' => $dirty,
                'created_by' => $actor->id,
            ]);
            $order->lines()->createMany($lines);
            $this->audit->record($actor, $company, 'carwash.work_order.created', $order, null, [
                'number' => $number, 'plate' => $vehicle->plate, 'services' => array_column($lines, 'description'),
            ]);

            return $order;
        });
    }
}
