<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\WorkOrderSource;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\Vehicle;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\AgendaService;
use App\Domains\Carwash\Services\PlateNormalizer;
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
 * born with. The vehicle comes by its id or by its plate: an existing plate is the same vehicle (never a duplicate) and a
 * new one is registered. The customer is the one of this order (anyone may bring the vehicle; it is remembered as the
 * vehicle's last customer). Configuración may pick the washer (rotation), the free work area and add
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
        private readonly SaveVehicleAction $vehicles,
        private readonly PlateNormalizer $plates,
    ) {}

    /**
     * @param  array<string, mixed>  $data  vehiclePublicId or plate (+ vehicleSpecPublicId / vehicleTypePublicId), customerPublicId,
     *                                      servicePublicIds, items (articles), washerPublicId, scheduledAt, workAreaPublicId, notes, dirty
     */
    public function execute(array $data, User $actor): WorkOrder
    {
        $company = $this->context->company();
        $settings = CarwashSetting::for($company);
        $vehicle = $this->vehicle($data, $actor);
        if (! $vehicle->is_active) {
            throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_INACTIVE, 'El vehículo está inactivo.', 422);
        }
        $customerId = filled($data['customerPublicId'] ?? null)
            ? $company->customers()->where('public_id', $data['customerPublicId'])->where('is_active', true)->firstOrFail()->id
            : $vehicle->customer_id;
        if (! $customerId) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Elige el cliente de la orden.', 422, ['errors' => ['customerPublicId' => ['Elige el cliente de la orden.']]]);
        }
        if ($vehicle->customer_id !== $customerId) {
            $vehicle->forceFill(['customer_id' => $customerId])->save();
        }
        $dirty = (bool) ($data['dirty'] ?? false);
        $scheduledAt = empty($data['scheduledAt']) ? null : CarbonImmutable::parse($data['scheduledAt'])->setTimezone(config('app.timezone'));
        $reserved = $scheduledAt !== null;
        $lines = [...$this->lines->resolve($vehicle, $data['servicePublicIds']), ...$this->lines->surcharges($company->id, $dirty, $scheduledAt), ...$this->lines->articles($company->id, $data['items'] ?? [])];

        return DB::transaction(function () use ($company, $settings, $vehicle, $customerId, $lines, $scheduledAt, $reserved, $data, $actor, $dirty): WorkOrder {
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
                'customer_id' => $customerId,
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

    /** The vehicle by its id, or by its plate: the same plate is the same vehicle (its specification may be updated). */
    private function vehicle(array $data, User $actor): Vehicle
    {
        $companyId = $this->context->company()->id;
        if (filled($data['vehiclePublicId'] ?? null)) {
            return Vehicle::query()->where('company_id', $companyId)->where('public_id', $data['vehiclePublicId'])->first()
                ?? throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_NOT_FOUND, 'El vehículo no existe.', 404);
        }
        $spec = array_filter(['vehicleSpecPublicId' => $data['vehicleSpecPublicId'] ?? null, 'vehicleTypePublicId' => $data['vehicleTypePublicId'] ?? null]);
        $vehicle = Vehicle::query()->where('company_id', $companyId)->where('plate', $this->plates->normalize((string) $data['plate']))->first();
        if (! $vehicle) {
            return $this->vehicles->execute(new Vehicle, ['plate' => $data['plate'], ...$spec, 'isActive' => true], $actor);
        }
        if (isset($spec['vehicleSpecPublicId']) && $spec['vehicleSpecPublicId'] !== $vehicle->model?->public_id) {
            return $this->vehicles->execute($vehicle, ['vehicleSpecPublicId' => $spec['vehicleSpecPublicId']], $actor);
        }

        return $vehicle;
    }
}
