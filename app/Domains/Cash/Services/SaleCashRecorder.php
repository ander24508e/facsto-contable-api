<?php

namespace App\Domains\Cash\Services;

use App\Domains\Cash\Models\CashMovement;
use App\Domains\Cash\Models\CashSession;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * Puts the cash of an invoice into its cash register. Only payment 01 (sin utilización del sistema
 * financiero) is money in the drawer, and of a CREDITO only its down payment; cards and transfers are
 * reported but never counted.
 */
final class SaleCashRecorder
{
    public const CASH_CODE = '01';

    public function cashOf(Sale $sale): string
    {
        $cash = (string) $sale->paymentMethods->where('method_code', self::CASH_CODE)->where('is_credit', false)->sum('amount');

        return bcadd($cash, (string) $sale->paymentMethods->where('is_credit', true)->sum('down_payment'), 2);
    }

    /**
     * The cash a credit note gives back, taken out of an open cash register. It never leaves the drawer
     * below zero.
     */
    public function refund(ElectronicDocument $creditNote, CashSession $session, User $actor): void
    {
        $session = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
        $amount = bcadd((string) $creditNote->total, '0', 2);
        $expected = $session->balance()['expected'];
        if (bccomp($amount, $expected, 2) > 0) {
            throw new ApiException(ApiErrorCode::CASH_INSUFFICIENT, "En la caja sólo hay \${$expected}; no alcanza para devolver \${$amount}.", 409);
        }
        CashMovement::query()->create([
            'company_id' => $creditNote->company_id, 'cash_session_id' => $session->id, 'type' => CashMovement::OUT,
            'source' => CashMovement::REFUND, 'amount' => $amount, 'concept' => 'Devolución '.$creditNote->full_number,
            'electronic_document_id' => $creditNote->id, 'created_by' => $actor->id,
        ]);
    }

    /**
     * Writes (or, after a correction, rewrites) the SALE movement of the invoice. A closed session is
     * history and is never changed.
     */
    public function record(Sale $sale, ElectronicDocument $invoice, User $actor): void
    {
        $session = CashSession::query()->whereKey($sale->cash_session_id)->lockForUpdate()->first();
        if (! $session || $session->status !== CashSession::OPEN) {
            return;
        }
        $amount = $this->cashOf($sale);
        $movement = CashMovement::query()->where('sale_id', $sale->id)->where('source', CashMovement::SALE)->first();
        if (bccomp($amount, '0', 2) <= 0) {
            $movement?->delete();

            return;
        }
        $values = ['amount' => $amount, 'concept' => 'Venta '.$invoice->full_number, 'electronic_document_id' => $invoice->id];
        if ($movement) {
            $movement->forceFill($values)->save();

            return;
        }
        CashMovement::query()->create([
            ...$values,
            'company_id' => $sale->company_id, 'cash_session_id' => $session->id, 'type' => CashMovement::IN,
            'source' => CashMovement::SALE, 'sale_id' => $sale->id, 'created_by' => $actor->id,
        ]);
    }
}
