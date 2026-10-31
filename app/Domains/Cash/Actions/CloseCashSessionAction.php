<?php

namespace App\Domains\Cash\Actions;

use App\Domains\Cash\Models\CashSession;
use App\Domains\Cash\Services\CashDenominations;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Closes a cash register: the money counted (fixed amount or bills and coins) against what it should hold
 * (opening + money in − money out). The difference is kept: positive is a surplus, negative a shortage.
 */
final class CloseCashSessionAction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array{mode: string, amount?: string|null, denominations?: array<string, array<string, int>>|null, notes?: string|null}  $data */
    public function execute(User $user, Company $company, CashSession $session, array $data): CashSession
    {
        return DB::transaction(function () use ($user, $company, $session, $data): CashSession {
            $session = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== CashSession::OPEN) {
                throw new ApiException(ApiErrorCode::CASH_SESSION_NOT_OPEN, 'Esta caja ya está cerrada.', 409);
            }

            [$counted, $denominations] = CashDenominations::resolve($data['mode'], $data['amount'] ?? null, $data['denominations'] ?? null);
            $expected = $session->balance()['expected'];
            $notes = trim((string) ($data['notes'] ?? ''));

            $session->forceFill([
                'status' => CashSession::CLOSED,
                'closed_by' => $user->id,
                'closed_at' => now(),
                'closing_mode' => $data['mode'],
                'closing_amount' => $counted,
                'closing_denominations' => $denominations,
                'expected_amount' => $expected,
                'difference' => bcsub($counted, $expected, 2),
                'closing_notes' => $notes !== '' ? $notes : null,
            ])->save();
            $this->audit->record($user, $company, 'cash_session.closed', $session, ['expected' => $expected], [
                'counted' => $counted,
                'difference' => (string) $session->difference,
            ]);

            return $session;
        });
    }
}
