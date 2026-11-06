<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\BillingStatus;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\ServicePriceItems;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Services\ItemContent;
use App\Domains\Inventory\Services\StockLedger;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Moves an order along PENDING → ARRIVED → IN_PROGRESS → READY → DELIVERED, stamping each stage.
 * Delivering needs the invoice issued in Ventas; cancelling needs a reason and no live sale. Finishing the wash
 * (READY) uses up the supplies of each line's price in the kardex (CONSUMPTION); before that nothing is taken.
 */
final class TransitionWorkOrderAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditRecorder $audit,
        private readonly StockLedger $ledger,
        private readonly ItemContent $content,
    ) {}

    public function execute(WorkOrder $order, WorkOrderStatus $target, ?string $reason, User $actor): WorkOrder
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($order, $target, $reason, $actor, $company): WorkOrder {
            $order = WorkOrder::query()->with('sale.electronicDocument')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $order->status;
            if (! in_array($target, $from->next(), true)) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_TRANSITION_INVALID, 'La orden no puede pasar a ese estado.', 409, [
                    'status' => $from->value, 'allowed' => array_map(fn (WorkOrderStatus $status) => $status->value, $from->next()),
                ]);
            }
            $billing = BillingStatus::of($order->sale);
            if ($target === WorkOrderStatus::DELIVERED && ! $billing->isInvoiced()) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_BILLED, 'Cobra y emite la factura antes de entregar el vehículo.', 409);
            }
            if ($target === WorkOrderStatus::CANCELLED) {
                if ($billing !== BillingStatus::UNBILLED) {
                    throw new ApiException(ApiErrorCode::CARWASH_ORDER_ALREADY_BILLED, 'La orden ya tiene venta; cancela primero el borrador en Ventas.', 409);
                }
                if (trim((string) $reason) === '') {
                    throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Indica el motivo de la cancelación.', 422, ['errors' => ['reason' => ['Indica el motivo de la cancelación.']]]);
                }
                $order->cancel_reason = mb_substr(trim((string) $reason), 0, 300);
            }

            $order->status = $target;
            $order->{$target->timestampColumn()} = now();
            $order->save();
            if ($target === WorkOrderStatus::READY) {
                $this->consumeSupplies($order, $actor);
            }
            $this->audit->record($actor, $company, 'carwash.work_order.status_changed', $order, ['status' => $from->value], ['status' => $target->value, 'reason' => $order->cancel_reason]);

            return $order;
        });
    }

    private function consumeSupplies(WorkOrder $order, User $actor): void
    {
        $order->load(['vehicle', 'lines.servicePrice.supplies.item']);
        foreach ($order->lines as $line) {
            foreach ($line->servicePrice?->supplies ?? [] as $supply) {
                $units = bcmul($this->content->units($supply->item, (string) $supply->quantity, $supply->measure), (string) $line->quantity, 6);
                $this->ledger->consume($supply->item, $units, $actor, $order->code().' · '.$line->description.' · '.$order->vehicle->plate, ServicePriceItems::MODULE, $order->public_id);
            }
        }
    }
}
