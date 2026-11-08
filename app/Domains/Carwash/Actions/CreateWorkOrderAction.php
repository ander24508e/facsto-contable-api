<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\WorkOrderSource;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\Vehicle;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\AgendaService;
use App\Domains\Carwash\Services\WorkerDirectory;
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
 * Opens a work order for a vehicle with its services. OT numbering is per company and assigned while the
 * company row is locked: it never repeats nor skips. The customer is the vehicle's.
 */
final class CreateWorkOrderAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly WorkOrderLineResolver $lines,
        private readonly AgendaService $agenda,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data vehiclePublicId, servicePublicIds, washerPublicId, scheduledAt, workAreaPublicId, notes */
    public function execute(array $data, User $actor): WorkOrder
    {
        $company = $this->context->company();
        $vehicle = Vehicle::query()->where('company_id', $company->id)->where('public_id', $data['vehiclePublicId'])->first()
            ?? throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_NOT_FOUND, 'El vehículo no existe.', 404);
        if (! $vehicle->is_active) {
            throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_INACTIVE, 'El vehículo está inactivo.', 422);
        }
        $lines = $this->lines->resolve($vehicle, $data['servicePublicIds']);
        $washer = app(WorkerDirectory::class)->resolve($company, $data['washerPublicId'] ?? null);
        $scheduledAt = empty($data['scheduledAt']) ? null : CarbonImmutable::parse($data['scheduledAt'])->setTimezone(config('app.timezone'));
        $reserved = $scheduledAt !== null;
        // A reservation takes a work area at its time. A walk-in with an area takes it from now; without one, none.
        $area = $reserved || ! empty($data['workAreaPublicId']) ? $this->agenda->area($company, $data['workAreaPublicId'] ?? null) : null;
        if ($area && ! $reserved) {
            $scheduledAt = CarbonImmutable::now(config('app.timezone'))->startOfMinute();
        }

        return DB::transaction(function () use ($company, $vehicle, $lines, $washer, $scheduledAt, $reserved, $area, $data, $actor): WorkOrder {
            Company::query()->whereKey($company->id)->lockForUpdate()->first();
            $scheduledEnd = $area ? $this->agenda->reserve($company, $area, $scheduledAt, $lines) : null;
            $number = (int) WorkOrder::query()->where('company_id', $company->id)->max('number') + 1;

            $order = WorkOrder::query()->create([
                'company_id' => $company->id,
                'number' => $number,
                'source' => $reserved ? WorkOrderSource::RESERVATION : WorkOrderSource::COUNTER,
                'vehicle_id' => $vehicle->id,
                'customer_id' => $vehicle->customer_id,
                'washer_id' => $washer?->id,
                'status' => WorkOrderStatus::PENDING,
                'scheduled_at' => $scheduledAt,
                'work_area_id' => $area?->id,
                'scheduled_end_at' => $scheduledEnd,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
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
