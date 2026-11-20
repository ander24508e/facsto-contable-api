<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\BillingStatus;
use App\Domains\Carwash\Enums\WorkOrderSource;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Models\WorkOrderLine;
use App\Domains\Carwash\Services\AgendaService;
use App\Domains\Carwash\Services\PlateNormalizer;
use App\Domains\Carwash\Services\WasherAssigner;
use App\Domains\Carwash\Services\WorkOrderVehicles;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edits an order (the pencil of Servicios and Órdenes) while it is not charged nor invoiced:
 * - Before the wash ends: everything (customer or contact, vehicle and its specification, services, products, notes,
 *   dirt). The time and the area only while it is pending; they are checked again against the agenda.
 * - With the wash done: only its products (add, take off, quantity); it cannot be washed and taken away unpaid.
 * The washer can change until the order is closed.
 */
final class UpdateWorkOrderAction
{
    /** What may be sent once the wash is done (the edit form sends the rest again untouched). */
    private const AFTER_WASH = ['items', 'washerPublicId'];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly AgendaService $agenda,
        private readonly AuditRecorder $audit,
        private readonly WasherAssigner $washers,
        private readonly WorkOrderVehicles $vehicles,
        private readonly PlateNormalizer $plates,
    ) {}

    /**
     * @param  array<string, mixed>  $data  vehiclePublicId or plate, vehicleSpecPublicId, servicePublicIds, items,
     *                                      customerPublicId, contactName, contactPhone, washerPublicId, scheduledAt,
     *                                      workAreaPublicId, notes, dirty
     */
    public function execute(WorkOrder $order, array $data, User $actor): WorkOrder
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($order, $data, $actor, $company): WorkOrder {
            // The company row serialises reservations, as when creating an order.
            Company::query()->whereKey($company->id)->lockForUpdate()->first();
            $order = WorkOrder::query()->with(['sale.electronicDocument', 'sale.paymentMethods', 'vehicle.model', 'lines.service', 'customer', 'workArea'])->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status->isClosed()) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_EDITABLE, 'La orden ya está cerrada.', 409);
            }
            if (array_diff(array_keys($data), ['washerPublicId']) !== [] && $this->charged($order)) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_EDITABLE, 'La orden ya está cobrada; no se edita.', 409);
            }
            $washed = $order->status === WorkOrderStatus::READY;
            if ($washed && $this->changesBeyondProducts($order, $data)) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_EDITABLE, 'Con el lavado terminado sólo se cambian los productos.', 409);
            }
            $before = ['washer_id' => $order->washer_id, 'scheduled_at' => $order->scheduled_at?->toIso8601String(), 'work_area_id' => $order->work_area_id, 'notes' => $order->notes, 'vehicle_id' => $order->vehicle_id];
            $rescheduling = ! $washed && (array_key_exists('scheduledAt', $data) || array_key_exists('workAreaPublicId', $data)) && $this->reschedules($order, $data);

            if (array_key_exists('washerPublicId', $data)) {
                $order->washer_id = $this->washers->assign($company, $data['washerPublicId'], $order->washer_id, $order)?->id;
            }
            if ($rescheduling) {
                if ($order->status !== WorkOrderStatus::PENDING) {
                    throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_EDITABLE, 'Sólo se reprograma una orden pendiente.', 409);
                }
                if (array_key_exists('scheduledAt', $data)) {
                    $order->scheduled_at = $data['scheduledAt'] === null ? null : CarbonImmutable::parse($data['scheduledAt'])->setTimezone(config('app.timezone'));
                }
                if (array_key_exists('workAreaPublicId', $data) && $data['workAreaPublicId'] !== null) {
                    $order->work_area_id = $this->agenda->area($company, $data['workAreaPublicId'])->id;
                }
            }

            $servicesChanged = false;
            if (! $washed) {
                $this->contact($order, $data, $company);
                if (array_key_exists('notes', $data)) {
                    $order->notes = trim((string) $data['notes']) ?: null;
                }
                if (array_key_exists('dirty', $data)) {
                    $order->dirty = (bool) $data['dirty'];
                }
                // Another vehicle, or another specification for it.
                if (filled($data['vehiclePublicId'] ?? null) || filled($data['plate'] ?? null) || filled($data['vehicleSpecPublicId'] ?? null)) {
                    $vehicle = $this->vehicles->vehicle($company->id, [
                        ...(filled($data['vehiclePublicId'] ?? null) || filled($data['plate'] ?? null) ? [] : ['vehiclePublicId' => $order->vehicle->public_id]),
                        ...array_intersect_key($data, array_flip(['vehiclePublicId', 'plate', 'vehicleSpecPublicId'])),
                    ], $actor);
                    $servicesChanged = $vehicle->id !== $order->vehicle_id || $vehicle->vehicle_model_id !== $order->vehicle->vehicle_model_id;
                    $order->vehicle_id = $vehicle->id;
                    $order->setRelation('vehicle', $vehicle);
                }
                if ($servicesChanged || array_key_exists('servicePublicIds', $data) || array_key_exists('dirty', $data) || $rescheduling) {
                    // The services (or, if they are not sent, the ones it has) again, with the surcharges of its time and dirt.
                    $services = $data['servicePublicIds'] ?? $this->serviceIds($order);
                    $lines = $this->vehicles->serviceLines($order->vehicle, $services, (bool) $order->dirty, $order->scheduled_at);
                    $order->lines()->where('kind', '!=', WorkOrderLine::PRODUCT)->delete();
                    $order->lines()->createMany($lines);
                    $servicesChanged = true;
                }
            }
            if (array_key_exists('items', $data)) {
                $order->lines()->where('kind', WorkOrderLine::PRODUCT)->delete();
                $order->lines()->createMany($this->vehicles->productLines($company->id, $data['items'] ?? []));
            }

            if ($order->scheduled_at === null) {
                // Without a time it is a walk-in again: it frees its area.
                $order->work_area_id = null;
                $order->scheduled_end_at = null;
            } elseif ($servicesChanged) {
                $area = $order->work_area_id ? $order->workArea()->first() : $this->agenda->area($company, null);
                $lines = $order->lines()->get(['service_id', 'duration_minutes'])->map(fn ($line) => ['service_id' => $line->service_id, 'duration_minutes' => $line->duration_minutes])->all();
                $order->scheduled_end_at = $this->agenda->reserve($company, $area, $order->scheduled_at, $lines, $order, $order->source === WorkOrderSource::RESERVATION);
            }
            $order->save();
            $this->audit->record($actor, $company, 'carwash.work_order.updated', $order, $before, [
                'washer_id' => $order->washer_id, 'scheduled_at' => $order->scheduled_at?->toIso8601String(), 'work_area_id' => $order->work_area_id, 'notes' => $order->notes, 'vehicle_id' => $order->vehicle_id,
            ]);

            return $order;
        });
    }

    /** Charged: invoiced, or its payment already in the drawer. A draft prepared by «Cobro automático» is still editable. */
    private function charged(WorkOrder $order): bool
    {
        $billing = BillingStatus::of($order->sale);
        if ($billing === BillingStatus::UNBILLED) {
            return false;
        }

        return $billing !== BillingStatus::DRAFT || $order->sale->paymentMethods->isNotEmpty();
    }

    /** With the wash done, whether something other than its products would change. */
    private function changesBeyondProducts(WorkOrder $order, array $data): bool
    {
        $current = $this->serviceIds($order);
        sort($current);
        $same = [
            'servicePublicIds' => function ($value) use ($current): bool {
                $sent = array_values(array_unique($value ?? []));
                sort($sent);

                return $sent === $current;
            },
            'vehiclePublicId' => fn ($value) => blank($value) || $value === $order->vehicle->public_id,
            'plate' => fn ($value) => blank($value) || $this->plates->normalize((string) $value) === $order->vehicle->plate,
            'vehicleSpecPublicId' => fn ($value) => blank($value) || $value === $order->vehicle->model?->public_id,
            'customerPublicId' => fn ($value) => $value === $order->customer?->public_id,
            'contactName' => fn ($value) => trim((string) $value) === trim((string) ($order->contact_name ?? '')),
            'contactPhone' => fn ($value) => trim((string) $value) === trim((string) ($order->contact_phone ?? '')),
            'notes' => fn ($value) => (trim((string) $value) ?: null) === $order->notes,
            'dirty' => fn ($value) => (bool) $value === (bool) $order->dirty,
            'scheduledAt' => fn ($value) => $value === null ? $order->scheduled_at === null : (bool) $order->scheduled_at?->equalTo(CarbonImmutable::parse($value)),
            'workAreaPublicId' => fn ($value) => $value === null || $value === $order->workArea?->public_id,
        ];
        foreach ($data as $key => $value) {
            if (in_array($key, self::AFTER_WASH, true)) {
                continue;
            }
            if (isset($same[$key]) && ! $same[$key]($value)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the time or the area sent differ from the order's (the edit form sends them again untouched). */
    private function reschedules(WorkOrder $order, array $data): bool
    {
        if (array_key_exists('scheduledAt', $data)) {
            $value = $data['scheduledAt'];
            if ($value === null ? $order->scheduled_at !== null : ! $order->scheduled_at?->equalTo(CarbonImmutable::parse($value))) {
                return true;
            }
        }

        return array_key_exists('workAreaPublicId', $data) && $data['workAreaPublicId'] !== null && $data['workAreaPublicId'] !== $order->workArea?->public_id;
    }

    private function contact(WorkOrder $order, array $data, Company $company): void
    {
        if (array_key_exists('customerPublicId', $data)) {
            $order->customer_id = filled($data['customerPublicId'])
                ? $company->customers()->where('public_id', $data['customerPublicId'])->where('is_active', true)->firstOrFail()->id
                : null;
        }
        if (array_key_exists('contactName', $data)) {
            $order->contact_name = trim((string) $data['contactName']) ?: null;
        }
        if (array_key_exists('contactPhone', $data)) {
            $order->contact_phone = trim((string) $data['contactPhone']) ?: null;
        }
        if (! $order->customer_id && ! $order->contact_name) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Escribe el nombre del cliente.', 422, ['errors' => ['contactName' => ['Escribe el nombre del cliente.']]]);
        }
    }

    /** @return list<string> */
    private function serviceIds(WorkOrder $order): array
    {
        return $order->lines()->where('kind', WorkOrderLine::SERVICE)->with('service')->get()->pluck('service.public_id')->filter()->values()->all();
    }
}
