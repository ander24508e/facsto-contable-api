<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\BillingStatus;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\Washer;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\AgendaService;
use App\Domains\Carwash\Services\WorkOrderLineResolver;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Corrects an order. Services and notes change only before washing starts and while there is no sale;
 * the time only while pending. The washer can change until the order is closed.
 */
final class UpdateWorkOrderAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly WorkOrderLineResolver $lines,
        private readonly AgendaService $agenda,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data servicePublicIds, washerPublicId, scheduledAt, notes */
    public function execute(WorkOrder $order, array $data, User $actor): WorkOrder
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($order, $data, $actor, $company): WorkOrder {
            $order = WorkOrder::query()->with('sale.electronicDocument')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status->isClosed()) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_EDITABLE, 'La orden ya está cerrada.', 409);
            }
            $editingContent = array_key_exists('servicePublicIds', $data) || array_key_exists('notes', $data);
            if ($editingContent && (! $order->status->isEditable() || BillingStatus::of($order->sale) !== BillingStatus::UNBILLED)) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_EDITABLE, 'Los servicios sólo se cambian antes de lavar y antes de cobrar.', 409);
            }
            $before = ['washer_id' => $order->washer_id, 'scheduled_at' => $order->scheduled_at?->toIso8601String(), 'notes' => $order->notes];

            if (array_key_exists('washerPublicId', $data)) {
                $order->washer_id = $data['washerPublicId'] === null ? null : (Washer::query()->where('company_id', $company->id)
                    ->where('public_id', $data['washerPublicId'])->where('is_active', true)->first()
                    ?? throw new ApiException(ApiErrorCode::CARWASH_WASHER_NOT_FOUND, 'El lavador no existe o está inactivo.', 422))->id;
            }
            if (array_key_exists('scheduledAt', $data)) {
                if ($order->status !== WorkOrderStatus::PENDING) {
                    throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_EDITABLE, 'Sólo se reprograma una orden pendiente.', 409);
                }
                $scheduledAt = $data['scheduledAt'] === null ? null : CarbonImmutable::parse($data['scheduledAt'])->setTimezone(config('app.timezone'));
                if ($scheduledAt) {
                    $this->agenda->assertAvailable($company, $scheduledAt, $order);
                }
                $order->scheduled_at = $scheduledAt;
            }
            if (array_key_exists('notes', $data)) {
                $order->notes = trim((string) $data['notes']) ?: null;
            }
            if (array_key_exists('servicePublicIds', $data)) {
                $lines = $this->lines->resolve($order->vehicle, $data['servicePublicIds']);
                $order->lines()->delete();
                $order->lines()->createMany($lines);
            }
            $order->save();
            $this->audit->record($actor, $company, 'carwash.work_order.updated', $order, $before, [
                'washer_id' => $order->washer_id, 'scheduled_at' => $order->scheduled_at?->toIso8601String(), 'notes' => $order->notes,
            ]);

            return $order;
        });
    }
}
