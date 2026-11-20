<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\BillingStatus;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\WorkOrderPricing;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Actions\EnsureFinalConsumerAction;
use App\Domains\Sales\Actions\CreateSaleDraftAction;
use App\Domains\Sales\Actions\UpdateSaleDraftAction;
use App\Domains\Sales\DTOs\SaleDraftData;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * «Cobrar»: the order becomes a sale draft in Ventas, with the order's customer (consumidor final when it only has a
 * contact name) and one line per service and product
 * (the catalog item, so price and taxes are FACSTO's), with the combo, loyalty and rounding discounts of
 * Configuración (WorkOrderPricing). The cashier adds the payment and issues it there.
 * Charging again refreshes the same draft; a cancelled draft is replaced by a new one. Uses the Sales
 * actions, in the same transaction: the core never knows the Lavadora exists.
 */
final class ChargeWorkOrderAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly CreateSaleDraftAction $create,
        private readonly UpdateSaleDraftAction $update,
        private readonly AuditRecorder $audit,
        private readonly WorkOrderPricing $pricing,
        private readonly EnsureFinalConsumerAction $finalConsumer,
    ) {}

    public function execute(WorkOrder $order, User $actor): WorkOrder
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($order, $actor, $company): WorkOrder {
            $order = WorkOrder::query()->with(['lines.item', 'vehicle', 'customer', 'sale.electronicDocument'])->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::READY], true)) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_READY_FOR_BILLING, 'Sólo se cobra una orden en lavado o lista.', 409);
            }
            $billing = BillingStatus::of($order->sale);
            if (! in_array($billing, [BillingStatus::UNBILLED, BillingStatus::DRAFT], true)) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_ALREADY_BILLED, 'La orden ya tiene factura.', 409);
            }

            $priced = $this->pricing->lines($order);
            $extras = [...array_map(fn (string $combo) => 'Combo '.$combo, $priced['combos']), ...($priced['loyaltyFree'] ? ['Lavado gratis por fidelidad'] : [])];
            $draft = SaleDraftData::fromValidated([
                'customerPublicId' => ($order->customer ?? $this->finalConsumer->execute($actor))->public_id,
                'lines' => $priced['lines'],
                'notes' => mb_substr(trim(implode(' · ', array_filter([$order->code(), 'Placa '.$order->vehicle->plate, $order->customer ? null : $order->contactName(), ...$extras, $order->notes]))), 0, 1000),
            ]);
            $sale = $billing === BillingStatus::DRAFT
                ? $this->update->execute($order->sale, $draft, $actor)
                : $this->create->execute($draft, $actor);
            $sale->forceFill(['source_module' => 'carwash', 'source_reference' => $order->public_id])->saveQuietly();

            $order->sale_id = $sale->id;
            $order->loyalty_free = $priced['loyaltyFree'];
            $order->save();
            $this->audit->record($actor, $company, 'carwash.work_order.charged', $order, null, ['sale' => $sale->public_id, 'number' => $sale->displayNumber()]);

            return $order;
        });
    }
}
