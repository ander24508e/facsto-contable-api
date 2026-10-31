<?php

namespace App\Domains\Cash\Actions;

use App\Domains\Cash\Models\CashMovement;
use App\Domains\Cash\Models\CashSession;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** Money in or out of an open cash register; money out can never leave the drawer below zero. */
final class RegisterCashMovementAction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array{type: string, amount: string, concept: string}  $data */
    public function execute(User $user, Company $company, CashSession $session, array $data): CashMovement
    {
        return DB::transaction(function () use ($user, $company, $session, $data): CashMovement {
            $session = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== CashSession::OPEN) {
                throw new ApiException(ApiErrorCode::CASH_SESSION_NOT_OPEN, 'La caja está cerrada; ábrela para registrar movimientos.', 409);
            }

            $amount = bcadd($data['amount'], '0', 2);
            if (bccomp($amount, '0', 2) <= 0) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El monto debe ser mayor a $0.00.', 422);
            }
            $expected = $session->balance()['expected'];
            if ($data['type'] === CashMovement::OUT && bccomp($amount, $expected, 2) > 0) {
                throw new ApiException(ApiErrorCode::CASH_INSUFFICIENT, "En la caja sólo hay \${$expected}; el egreso no puede ser mayor.", 409);
            }

            $movement = CashMovement::query()->create([
                'company_id' => $company->id,
                'cash_session_id' => $session->id,
                'type' => $data['type'],
                'amount' => $amount,
                'concept' => trim($data['concept']),
                'created_by' => $user->id,
            ]);
            $this->audit->record($user, $company, 'cash_movement.created', $movement, null, ['type' => $movement->type, 'amount' => $amount, 'concept' => $movement->concept]);

            return $movement;
        });
    }
}
