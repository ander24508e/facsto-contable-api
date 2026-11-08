<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\BillingStatus;
use App\Domains\Carwash\Enums\WorkOrderStatus;
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
 * Corrects an order. Services and notes change only before washing starts and while there is no sale;
 * the time and the work area only while pending (they are checked again against the agenda, also when the
 * services change). The washer can change until the order is closed.
 */
final class UpdateWorkOrderAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly WorkOrderLineResolver $lines,
        private readonly AgendaService $agenda,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data servicePublicIds, washerPublicId, scheduledAt, workAreaPublicId, notes */
    public function execute(WorkOrder $order, array $data, User $actor): WorkOrder
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($order, $data, $actor, $company): WorkOrder {
            // The company row serialises reservations, as when creating an order.
            Company::query()->whereKey($company->id)->lockForUpdate()->first();
            $order = WorkOrder::query()->with('sale.electronicDocument')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status->isClosed()) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_EDITABLE, 'La orden ya está cerrada.', 409);
            }
            $editingContent = array_key_exists('servicePublicIds', $data) || array_key_exists('notes', $data);
            if ($editingContent && (! $order->status->isEditable() || BillingStatus::of($order->sale) !== BillingStatus::UNBILLED)) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_EDITABLE, 'Los servicios sólo se cambian antes de lavar y antes de cobrar.', 409);
            }
            $before = ['washer_id' => $order->washer_id, 'scheduled_at' => $order->scheduled_at?->toIso8601String(), 'work_area_id' => $order->work_area_id, 'notes' => $order->notes];
            $rescheduling = array_key_exists('scheduledAt', $data) || array_key_exists('workAreaPublicId', $data);

            if (array_key_exists('washerPublicId', $data)) {
                $order->washer_id = app(WorkerDirectory::class)->resolve($company, $data['washerPublicId'], $order->washer_id)?->id;
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
            if (array_key_exists('notes', $data)) {
                $order->notes = trim((string) $data['notes']) ?: null;
            }
            if (array_key_exists('servicePublicIds', $data)) {
                $lines = $this->lines->resolve($order->vehicle, $data['servicePublicIds']);
                $order->lines()->delete();
                $order->lines()->createMany($lines);
            }
            if ($order->scheduled_at === null) {
                // Without a time it is a walk-in again: it frees its area.
                $order->work_area_id = null;
                $order->scheduled_end_at = null;
            } elseif ($rescheduling || array_key_exists('servicePublicIds', $data)) {
                $area = $order->work_area_id ? $order->workArea()->first() : $this->agenda->area($company, null);
                $lines = $order->lines()->get(['service_id', 'duration_minutes'])->map(fn ($line) => ['service_id' => $line->service_id, 'duration_minutes' => $line->duration_minutes])->all();
                $order->scheduled_end_at = $this->agenda->reserve($company, $area, $order->scheduled_at, $lines, $order);
            }
            $order->save();
            $this->audit->record($actor, $company, 'carwash.work_order.updated', $order, $before, [
                'washer_id' => $order->washer_id, 'scheduled_at' => $order->scheduled_at?->toIso8601String(), 'work_area_id' => $order->work_area_id, 'notes' => $order->notes,
            ]);

            return $order;
        });
    }
}
