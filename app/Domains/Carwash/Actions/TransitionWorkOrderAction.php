<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\BillingStatus;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\ServicePriceItems;
use App\Domains\Carwash\Services\WorkflowSteps;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Services\ItemContent;
use App\Domains\Inventory\Services\StockLedger;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Moves an order along PENDING → ARRIVED → IN_PROGRESS → READY → DELIVERED, stamping each stage. Configuración decides:
 * - › Servicios: whether «Llegó» and «Listo» are used (WorkflowSteps) and the reception checklist, answered when the
 *   vehicle is received (required when the company says so).
 * - › Órdenes: delivering needs the invoice issued unless «Entregar sin cobrar» is on; with «Cobro automático» the sale
 *   draft is created in Ventas when the wash ends (or when it starts, without «Listo»).
 * - › Insumos: the supplies of each line's price are used up (CONSUMPTION) on «Listo» or on «Entregado».
 * Cancelling needs a reason and no live sale.
 */
final class TransitionWorkOrderAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditRecorder $audit,
        private readonly StockLedger $ledger,
        private readonly ItemContent $content,
        private readonly WorkflowSteps $steps,
        private readonly ChargeWorkOrderAction $charge,
    ) {}

    /** @param list<array{label: string, checked?: bool, note?: ?string}>|null $checklist */
    public function execute(WorkOrder $order, WorkOrderStatus $target, ?string $reason, User $actor, ?array $checklist = null): WorkOrder
    {
        $company = $this->context->company();
        $settings = CarwashSetting::for($company);

        $order = DB::transaction(function () use ($order, $target, $reason, $actor, $company, $settings, $checklist): WorkOrder {
            $order = WorkOrder::query()->with('sale.electronicDocument')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $order->status;
            $allowed = $this->steps->next($from, $settings);
            if (! in_array($target, $allowed, true)) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_TRANSITION_INVALID, 'La orden no puede pasar a ese estado.', 409, [
                    'status' => $from->value, 'allowed' => array_map(fn (WorkOrderStatus $status) => $status->value, $allowed),
                ]);
            }
            $billing = BillingStatus::of($order->sale);
            // Delivered: with its invoice, with its payment in the drawer (cobrada «por facturar») or when Configuración
            // › Órdenes allows delivering without charging.
            if ($target === WorkOrderStatus::DELIVERED && ! $billing->isInvoiced() && ! $this->paidDraft($order) && ! $settings->allow_unbilled_delivery) {
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
            if ($target === $this->steps->reception($settings)) {
                $order->checklist = $this->checklist($settings, $checklist);
            }

            $order->status = $target;
            $order->{$target->timestampColumn()} = now();
            $order->save();
            $consumeOn = $settings->consume_supplies_on === 'DELIVERED' ? WorkOrderStatus::DELIVERED : $this->steps->finished($settings);
            if ($target === $consumeOn) {
                $this->consumeSupplies($order, $actor);
            }
            $this->audit->record($actor, $company, 'carwash.work_order.status_changed', $order, ['status' => $from->value], ['status' => $target->value, 'reason' => $order->cancel_reason]);

            return $order;
        });

        // «Cobro automático»: the sale draft waits in Ventas when the wash ends. If it cannot be prepared (an item
        // without a valid tax, for example) the order keeps its new state and «Cobrar» stays at hand.
        $chargeOn = $settings->use_ready_step ? WorkOrderStatus::READY : WorkOrderStatus::IN_PROGRESS;
        if ($settings->billing_mode === CarwashSetting::BILLING_AUTO && $target === $chargeOn && BillingStatus::of($order->sale) === BillingStatus::UNBILLED) {
            try {
                $order = $this->charge->execute($order, $actor);
            } catch (ApiException) {
                // Charged by hand later.
            }
        }

        return $order;
    }

    /**
     * The answers to Configuración › Servicios «Checklist de recepción», in its order. Required: every item answered.
     *
     * @param  list<array{label: string, checked?: bool, note?: ?string}>|null  $answers
     * @return list<array{label: string, checked: bool, note: ?string}>|null
     */
    private function checklist(CarwashSetting $settings, ?array $answers): ?array
    {
        $items = $settings->checklistItems();
        if ($items === []) {
            return null;
        }
        $byLabel = collect($answers ?? [])->keyBy(fn (array $answer) => trim((string) $answer['label']));
        if ($settings->require_checklist && ($answers === null || collect($items)->contains(fn (string $label) => ! $byLabel->has($label)))) {
            throw new ApiException(ApiErrorCode::CARWASH_CHECKLIST_REQUIRED, 'Completa el checklist de recepción del vehículo.', 422, ['items' => $items]);
        }
        if ($answers === null) {
            return null;
        }

        return array_map(fn (string $label): array => [
            'label' => $label,
            'checked' => (bool) ($byLabel->get($label)['checked'] ?? false),
            'note' => trim((string) ($byLabel->get($label)['note'] ?? '')) ?: null,
        ], $items);
    }

    /** Its sale is a draft whose payments cover the total (charged in the checkout window, still without invoice). */
    private function paidDraft(WorkOrder $order): bool
    {
        if (BillingStatus::of($order->sale) !== BillingStatus::DRAFT) {
            return false;
        }
        $order->sale->loadMissing('paymentMethods');

        return $order->sale->paymentMethods->isNotEmpty() && bccomp((string) $order->sale->paymentMethods->sum('amount'), (string) $order->sale->total, 2) === 0;
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
