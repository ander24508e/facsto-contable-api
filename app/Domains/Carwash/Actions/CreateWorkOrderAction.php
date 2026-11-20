<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\WorkOrderSource;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\AgendaService;
use App\Domains\Carwash\Services\WasherAssigner;
use App\Domains\Carwash\Services\WorkOrderVehicles;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Customers\Models\Customer;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opens a work order: one vehicle with its services and its products (another vehicle goes in another order).
 * Numbering is per company and assigned while the company row is locked: it never repeats nor skips, starts at
 * Configuración › Órdenes «Número inicial» and keeps the prefix it was born with. The vehicle comes by its id or by its
 * plate (the same plate is the same vehicle; a new one is registered with its specification, which is required). The customer comes from
 * Clientes (with cédula or RUC) or only as a contact (name and phone); the invoice identifies them when it is issued.
 * Configuración may pick the washer (rotation), the free work area and add the surcharges (extreme dirt, peak hours).
 */
final class CreateWorkOrderAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AgendaService $agenda,
        private readonly AuditRecorder $audit,
        private readonly WasherAssigner $washers,
        private readonly WorkOrderVehicles $vehicles,
    ) {}

    /**
     * @param  array<string, mixed>  $data  vehiclePublicId or plate (+ vehicleSpecPublicId, required),
     *                                      servicePublicIds, items, customerPublicId, contactName, contactPhone,
     *                                      washerPublicId, scheduledAt, workAreaPublicId, notes, dirty
     */
    public function execute(array $data, User $actor): WorkOrder
    {
        // All or nothing: a new vehicle is registered only if the order is.
        return DB::transaction(fn (): WorkOrder => $this->open($data, $actor));
    }

    /** @param array<string, mixed> $data */
    private function open(array $data, User $actor): WorkOrder
    {
        $company = $this->context->company();
        $settings = CarwashSetting::for($company);
        $vehicle = $this->vehicles->vehicle($company->id, $data, $actor);
        [$customer, $contactName, $contactPhone] = $this->contact($company, $data, $vehicle->customer_id);
        $dirty = (bool) ($data['dirty'] ?? false);
        $scheduledAt = empty($data['scheduledAt']) ? null : CarbonImmutable::parse($data['scheduledAt'])->setTimezone(config('app.timezone'));
        $reserved = $scheduledAt !== null;
        $lines = [...$this->vehicles->serviceLines($vehicle, $data['servicePublicIds'], $dirty, $scheduledAt), ...$this->vehicles->productLines($company->id, $data['items'] ?? [])];

        return DB::transaction(function () use ($company, $settings, $vehicle, $customer, $contactName, $contactPhone, $lines, $scheduledAt, $reserved, $data, $actor, $dirty): WorkOrder {
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
                'customer_id' => $customer?->id,
                'contact_name' => $contactName,
                'contact_phone' => $contactPhone,
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
            // The vehicle remembers who brought it last (a suggestion for the next visit).
            if ($customer && $vehicle->customer_id !== $customer->id) {
                $vehicle->forceFill(['customer_id' => $customer->id])->save();
            }
            $this->audit->record($actor, $company, 'carwash.work_order.created', $order, null, [
                'number' => $number, 'plate' => $vehicle->plate, 'services' => array_column($lines, 'description'),
            ]);

            return $order;
        });
    }

    /**
     * The customer from Clientes, or only the contact typed at the counter; without either, the vehicle's last customer.
     *
     * @return array{0: ?Customer, 1: ?string, 2: ?string}
     */
    private function contact(Company $company, array $data, ?int $lastCustomerId): array
    {
        $name = trim((string) ($data['contactName'] ?? '')) ?: null;
        $phone = trim((string) ($data['contactPhone'] ?? '')) ?: null;
        $customer = match (true) {
            filled($data['customerPublicId'] ?? null) => $company->customers()->where('public_id', $data['customerPublicId'])->where('is_active', true)->firstOrFail(),
            $name === null && $lastCustomerId !== null => $company->customers()->whereKey($lastCustomerId)->first(),
            default => null,
        };
        if (! $customer && $name === null) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Escribe el nombre del cliente.', 422, ['errors' => ['contactName' => ['Escribe el nombre del cliente.']]]);
        }

        return [$customer, $name ?? $customer?->legal_name, $phone ?? $customer?->phone];
    }
}
