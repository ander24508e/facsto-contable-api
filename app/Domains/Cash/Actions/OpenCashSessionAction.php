<?php

namespace App\Domains\Cash\Actions;

use App\Domains\Cash\Models\CashSession;
use App\Domains\Cash\Services\CashDenominations;
use App\Domains\Cash\Services\CashTenderValues;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\EmissionPoint;
use App\Domains\Inventory\Models\Warehouse;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Opens a cash register for the day: an active emission point of an active establishment, with that
 * establishment's warehouse. The date is the server's, and with the counter the total is recomputed here.
 */
final class OpenCashSessionAction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{emissionPointPublicId: string, warehousePublicId: string, mode: string, amount?: string|null, denominations?: array<string, array<string, int>>|null, values?: list<array{type: string, amount: string}>|null, notes?: string|null}  $data
     */
    public function execute(User $user, Company $company, array $data): CashSession
    {
        return DB::transaction(function () use ($user, $company, $data): CashSession {
            $point = EmissionPoint::query()->where('company_id', $company->id)->where('public_id', $data['emissionPointPublicId'])
                ->with('establishment')->lockForUpdate()->first();
            if (! $point || ! $point->is_active || ! $point->establishment->is_active) {
                throw new ApiException(ApiErrorCode::EMISSION_POINT_NOT_FOUND, 'La caja no está activa en la empresa actual.', 422);
            }

            $warehouse = Warehouse::query()->where('company_id', $company->id)->where('public_id', $data['warehousePublicId'])->first();
            if (! $warehouse || ! $warehouse->is_active || $warehouse->establishment_id !== $point->establishment_id) {
                throw new ApiException(ApiErrorCode::WAREHOUSE_NOT_FOUND, 'La bodega no está activa o no pertenece al establecimiento de la caja.', 422);
            }

            if (CashSession::query()->where('emission_point_id', $point->id)->where('status', CashSession::OPEN)->exists()) {
                throw new ApiException(ApiErrorCode::CASH_SESSION_ALREADY_OPEN, 'Esta caja ya está abierta; ciérrala antes de abrirla otra vez.', 409);
            }

            [$amount, $denominations] = CashDenominations::resolve($data['mode'], $data['amount'] ?? null, $data['denominations'] ?? null);
            $values = CashTenderValues::normalize($data['values'] ?? null);
            $notes = trim((string) ($data['notes'] ?? ''));

            $session = CashSession::query()->create([
                'company_id' => $company->id,
                'emission_point_id' => $point->id,
                'warehouse_id' => $warehouse->id,
                'opened_by' => $user->id,
                'opened_at' => now(),
                'opening_mode' => $data['mode'],
                'opening_amount' => $amount,
                'denominations' => $denominations,
                'opening_values' => $values ?: null,
                'notes' => $notes !== '' ? $notes : null,
                'status' => CashSession::OPEN,
            ]);
            $this->audit->record($user, $company, 'cash_session.opened', $session, null, [
                'emissionPoint' => $point->establishment->code.'-'.$point->code,
                'mode' => $data['mode'],
                'amount' => $amount,
                'otherValues' => CashTenderValues::total($values),
                'otherValueBreakdown' => $values,
            ]);

            return $session;
        });
    }
}
