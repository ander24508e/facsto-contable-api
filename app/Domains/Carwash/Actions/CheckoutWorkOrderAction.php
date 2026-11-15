<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\BillingStatus;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\WorkOrderPricing;
use App\Domains\Cash\Services\SaleCashRecorder;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\FiscalDocuments\Actions\IssueSaleInvoiceWithProformaAction;
use App\Domains\Sales\Actions\CreateSaleDraftAction;
use App\Domains\Sales\Actions\UpdateSaleDraftAction;
use App\Domains\Sales\DTOs\SaleDraftData;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * La ventana de cobro de una orden (sin salir de la Lavadora): cliente (consumidor final por defecto), productos
 * agregados, forma de pago y observaciones.
 * 1. La venta de la orden se crea o actualiza en Ventas (las acciones de Ventas, con los descuentos de la Lavadora) en
 *    la caja abierta, y su pago entra a la caja aunque todavía no tenga factura («por facturar»).
 * 2. «Factura» encendido: se emite la factura como en Ventas (numeración, stock, caja, firma y SRI). Apagado: la venta
 *    queda como borrador en Ventas, sin SRI, para emitirla después desde la orden.
 * 3. «Entregar»: la orden pasa a entregada (por «Listo» si aún estaba en lavado).
 * El pago debe ser igual al total; puede ser mixto (varias formas) o a crédito con sus cuotas, como en Ventas.
 * «Cuenta aparte» (`separate`, sólo en el primer cobro): algunos productos se cobran como otra venta, con su cliente, su
 * pago y su factura o borrador, sin los descuentos de la orden.
 */
final class CheckoutWorkOrderAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly WorkOrderPricing $pricing,
        private readonly CreateSaleDraftAction $create,
        private readonly UpdateSaleDraftAction $update,
        private readonly SaleCashRecorder $cash,
        private readonly IssueSaleInvoiceWithProformaAction $issue,
        private readonly TransitionWorkOrderAction $transition,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array{customerPublicId: string, cashSessionPublicId: string, extraLines?: list<array{itemPublicId: string, quantity: string}>, paymentMethods: list<array<string, mixed>>, notes?: ?string, invoice: bool, deliver: bool, separate?: ?array{customerPublicId: string, lines: list<array{itemPublicId: string, quantity: string}>, paymentMethods: list<array<string, mixed>>, invoice: bool}}  $data
     */
    public function execute(WorkOrder $order, array $data, User $actor): WorkOrder
    {
        $company = $this->context->company();
        $settings = CarwashSetting::for($company);

        $separate = $data['separate'] ?? null;
        [$sale, $other] = DB::transaction(function () use ($order, $data, $actor, $company, $separate): array {
            $order = WorkOrder::query()->with(['lines.item', 'vehicle', 'sale.electronicDocument'])->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, [WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::READY], true)) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_NOT_READY_FOR_BILLING, 'Sólo se cobra una orden en lavado o lista.', 409);
            }
            $billing = BillingStatus::of($order->sale);
            if (! in_array($billing, [BillingStatus::UNBILLED, BillingStatus::DRAFT], true)) {
                throw new ApiException(ApiErrorCode::CARWASH_ORDER_ALREADY_BILLED, 'La orden ya tiene factura.', 409);
            }

            // An article of the order marked «Aparte» goes to the separate sale, not to this one.
            $priced = $this->pricing->lines($order, $data['extraLines'] ?? [], array_column($separate['lines'] ?? [], 'itemPublicId'));
            $extras = [...array_map(fn (string $combo) => 'Combo '.$combo, $priced['combos']), ...($priced['loyaltyFree'] ? ['Lavado gratis por fidelidad'] : [])];
            $draft = SaleDraftData::fromValidated([
                'customerPublicId' => $data['customerPublicId'],
                'cashSessionPublicId' => $data['cashSessionPublicId'],
                'lines' => $priced['lines'],
                'paymentMethods' => $data['paymentMethods'],
                'notes' => mb_substr(trim(implode(' · ', array_filter([$order->code(), 'Placa '.$order->vehicle->plate, ...$extras, trim((string) ($data['notes'] ?? '')) ?: null]))), 0, 2000),
            ]);
            $sale = $billing === BillingStatus::DRAFT ? $this->update->execute($order->sale, $draft, $actor) : $this->create->execute($draft, $actor);
            $sale->forceFill(['source_module' => 'carwash', 'source_reference' => $order->public_id])->saveQuietly();
            $sale->load('paymentMethods');

            $this->assertPaid($sale, 'paymentMethods');
            // The money is in the drawer now; the invoice, when issued, takes over this same movement.
            $this->cash->recordDraft($sale, $actor);

            $other = null;
            if ($separate) {
                if ($billing !== BillingStatus::UNBILLED) {
                    throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'La cuenta aparte se cobra en el primer cobro de la orden; cóbrala desde Nueva venta.', 422, ['errors' => ['separate' => ['La orden ya fue cobrada.']]]);
                }
                $other = $this->create->execute(SaleDraftData::fromValidated([
                    'customerPublicId' => $separate['customerPublicId'],
                    'cashSessionPublicId' => $data['cashSessionPublicId'],
                    'lines' => $this->pricing->separate($company->id, $separate['lines'])['lines'],
                    'paymentMethods' => $separate['paymentMethods'],
                    'notes' => 'Cuenta aparte de '.$order->code().' · Placa '.$order->vehicle->plate,
                ]), $actor);
                $other->forceFill(['source_module' => 'carwash', 'source_reference' => $order->public_id])->saveQuietly();
                $other->load('paymentMethods');
                $this->assertPaid($other, 'separate.paymentMethods');
                $this->cash->recordDraft($other, $actor);
            }

            $order->forceFill(['sale_id' => $sale->id, 'loyalty_free' => $priced['loyaltyFree']])->save();
            $this->audit->record($actor, $company, 'carwash.work_order.checked_out', $order, null, [
                'sale' => $sale->public_id, 'total' => (string) $sale->total, 'invoice' => (bool) $data['invoice'], 'deliver' => (bool) $data['deliver'],
                'separate' => $other?->public_id, 'separateTotal' => $other ? (string) $other->total : null,
            ]);

            return [$sale, $other];
        });

        if ($data['invoice']) {
            $this->issue->execute($sale->refresh(), $actor, $data['cashSessionPublicId']);
        }
        if ($other !== null && ($separate['invoice'] ?? false)) {
            $this->issue->execute($other->refresh(), $actor, $data['cashSessionPublicId']);
        }
        $order->refresh();
        if ($data['deliver']) {
            if ($order->status === WorkOrderStatus::IN_PROGRESS && $settings->use_ready_step) {
                $order = $this->transition->execute($order, WorkOrderStatus::READY, null, $actor);
            }
            $order = $this->transition->execute($order, WorkOrderStatus::DELIVERED, null, $actor);
        }

        return $order;
    }

    private function assertPaid(Sale $sale, string $field): void
    {
        $paid = bcadd((string) $sale->paymentMethods->sum('amount'), '0', 2);
        if (bccomp($paid, (string) $sale->total, 2) !== 0) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, "El pago (\${$paid}) debe ser igual al total (\${$sale->total}).", 422, ['errors' => [$field => ['El pago debe ser igual al total.']]]);
        }
    }
}
