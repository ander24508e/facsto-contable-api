<?php

namespace App\Domains\Credit\Services;

use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;

/**
 * The installments of a sale or purchase on CREDITO, as «Configurar cuotas» sends them: a down payment
 * (abono) paid on the day and the balance in installments, each with its date and amount, together
 * exactly the balance. Used for accounts receivable and accounts payable alike.
 */
final class InstallmentPlan
{
    public const MAX = 120;

    /** The fixed frequencies of «Configurar cuotas». */
    public const FREQUENCIES = ['DAILY', 'BIWEEKLY', 'MONTHLY', 'SEMIANNUAL', 'ANNUAL'];

    /**
     * The credit terms of a payment: its down payment (0 up to less than the amount), frequency, notes
     * and installments for the balance.
     *
     * @return array{down_payment: string, installment_frequency: ?string, credit_notes: ?string, installments: list<array{number: int, due_date: string, amount: string}>}
     */
    public function terms(array $data, string $amount): array
    {
        $raw = (string) ($data['downPayment'] ?? '0');
        $downPayment = preg_match('/^\d{1,16}(\.\d{1,2})?$/', $raw) ? bcadd($raw, '0', 2) : null;
        if ($downPayment === null || bccomp($downPayment, $amount, 2) >= 0) {
            throw new ApiException(ApiErrorCode::INSTALLMENTS_INVALID, "El abono debe ser menor que \${$amount}.", 422);
        }
        $frequency = $data['installmentFrequency'] ?? null;
        if ($frequency !== null && ! in_array($frequency, self::FREQUENCIES, true)) {
            throw new ApiException(ApiErrorCode::INSTALLMENTS_INVALID, 'La frecuencia de las cuotas no es válida.', 422);
        }
        $notes = trim((string) ($data['creditNotes'] ?? ''));

        return [
            'down_payment' => $downPayment,
            'installment_frequency' => $frequency,
            'credit_notes' => $notes !== '' ? mb_substr($notes, 0, 500) : null,
            'installments' => $this->normalize($data['installments'] ?? null, bcsub($amount, $downPayment, 2)),
        ];
    }

    /**
     * @param  list<array{dueDate?: mixed, amount?: mixed}>|null  $rows
     * @return list<array{number: int, due_date: string, amount: string}>
     */
    public function normalize(?array $rows, string $amount, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now((string) config('sri.timezone')))->startOfDay();
        if (! $rows) {
            throw new ApiException(ApiErrorCode::INSTALLMENTS_INVALID, 'Configura las cuotas del pago a crédito.', 422);
        }
        if (count($rows) > self::MAX) {
            throw new ApiException(ApiErrorCode::INSTALLMENTS_INVALID, 'Se admiten hasta '.self::MAX.' cuotas.', 422);
        }

        $plan = [];
        $sum = '0.00';
        foreach ($rows as $row) {
            $value = (string) ($row['amount'] ?? '');
            $date = (string) ($row['dueDate'] ?? '');
            if (! preg_match('/^\d{1,16}(\.\d{1,2})?$/', $value) || bccomp($value, '0', 2) <= 0) {
                throw new ApiException(ApiErrorCode::INSTALLMENTS_INVALID, 'Cada cuota debe tener un valor mayor a cero.', 422);
            }
            $due = CarbonImmutable::createFromFormat('!Y-m-d', $date, $today->timezone);
            if (! $due || $due->format('Y-m-d') !== $date || $due->lt($today)) {
                throw new ApiException(ApiErrorCode::INSTALLMENTS_INVALID, 'Cada cuota necesita una fecha de hoy en adelante.', 422);
            }
            $plan[] = ['due_date' => $date, 'amount' => bcadd($value, '0', 2)];
            $sum = bcadd($sum, $value, 2);
        }
        if (bccomp($sum, $amount, 2) !== 0) {
            throw new ApiException(ApiErrorCode::INSTALLMENTS_INVALID, "Las cuotas suman \${$sum} y el saldo a crédito es \${$amount}.", 422, ['installmentsTotal' => $sum, 'amount' => bcadd($amount, '0', 2)]);
        }

        usort($plan, fn (array $a, array $b): int => strcmp($a['due_date'], $b['due_date']));

        return array_map(fn (array $row, int $index): array => ['number' => $index + 1, ...$row], $plan, array_keys($plan));
    }

    /** Days from today to the last installment: the «plazo» the invoice XML declares for that payment. */
    public function termInDays(array $plan, ?CarbonImmutable $today = null): string
    {
        $today = ($today ?? CarbonImmutable::now((string) config('sri.timezone')))->startOfDay();
        $last = CarbonImmutable::createFromFormat('!Y-m-d', end($plan)['due_date'], $today->timezone);

        return (string) max(0, (int) $today->diffInDays($last));
    }
}
