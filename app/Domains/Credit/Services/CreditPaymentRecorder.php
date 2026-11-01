<?php

namespace App\Domains\Credit\Services;

use App\Domains\Cash\Models\CashMovement;
use App\Domains\Cash\Models\CashSession;
use App\Domains\Cash\Services\CashSessionResolver;
use App\Domains\Credit\Models\CreditPayment;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registers what is collected from a customer or paid to a supplier on CREDITO. The amount covers the
 * installments from the oldest to the newest and never exceeds the balance. A collection is cash that
 * enters the cash register the sale was made in (its emission point), which must be open.
 */
final class CreditPaymentRecorder
{
    public function __construct(
        private readonly CashSessionResolver $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    public function collect(Sale $sale, string $amount, ?string $notes, User $actor): CreditPayment
    {
        return DB::transaction(function () use ($sale, $amount, $notes, $actor): CreditPayment {
            $this->allocate($sale->installments()->orderBy('number')->lockForUpdate()->get(), $amount);
            $origin = CashSession::query()->whereKey($sale->cash_session_id)->with('emissionPoint.establishment')->first()
                ?? throw new ApiException(ApiErrorCode::CASH_SESSION_NOT_FOUND, 'La venta no tiene caja.', 409);
            $session = $this->sessions->openAt($sale->company, $origin->emissionPoint);
            $invoice = $sale->electronicDocument?->full_number;
            $movement = CashMovement::query()->create([
                'company_id' => $sale->company_id, 'cash_session_id' => $session->id, 'type' => CashMovement::IN,
                'source' => CashMovement::CREDIT, 'amount' => $amount, 'sale_id' => $sale->id,
                'concept' => mb_substr('Cobro cuota '.($invoice ?? $sale->displayNumber()).' · '.$sale->customer_name, 0, 120),
                'created_by' => $actor->id,
            ]);
            $payment = CreditPayment::query()->create([
                'company_id' => $sale->company_id, 'sale_id' => $sale->id, 'amount' => $amount, 'notes' => $notes,
                'cash_movement_id' => $movement->id, 'created_by' => $actor->id,
            ]);
            $this->audit->record($actor, $sale->company, 'receivable.collected', $sale, null, ['amount' => $amount, 'cashSession' => $session->public_id]);

            return $payment;
        });
    }

    public function pay(Purchase $purchase, string $amount, ?string $notes, User $actor): CreditPayment
    {
        return DB::transaction(function () use ($purchase, $amount, $notes, $actor): CreditPayment {
            $this->allocate($purchase->installments()->orderBy('number')->lockForUpdate()->get(), $amount);
            $payment = CreditPayment::query()->create([
                'company_id' => $purchase->company_id, 'purchase_id' => $purchase->id, 'amount' => $amount, 'notes' => $notes, 'created_by' => $actor->id,
            ]);
            $this->audit->record($actor, $purchase->company, 'payable.paid', $purchase, null, ['amount' => $amount]);

            return $payment;
        });
    }

    /** Covers the installments in order; the amount must be above zero and at most the balance. */
    private function allocate(Collection $installments, string $amount): void
    {
        $balance = bcadd((string) $installments->sum(fn ($installment) => bcsub((string) $installment->amount, (string) $installment->paid_amount, 2)), '0', 2);
        if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $balance, 2) > 0) {
            throw new ApiException(ApiErrorCode::CREDIT_PAYMENT_EXCEEDS_BALANCE, "El pago debe ser mayor a cero y hasta el saldo de \${$balance}.", 422, ['balance' => $balance]);
        }
        $left = $amount;
        foreach ($installments as $installment) {
            $pending = bcsub((string) $installment->amount, (string) $installment->paid_amount, 2);
            if (bccomp($left, '0', 2) <= 0 || bccomp($pending, '0', 2) <= 0) {
                continue;
            }
            $applied = bccomp($left, $pending, 2) >= 0 ? $pending : $left;
            $installment->forceFill(['paid_amount' => bcadd((string) $installment->paid_amount, $applied, 2)])->save();
            $left = bcsub($left, $applied, 2);
        }
    }
}
