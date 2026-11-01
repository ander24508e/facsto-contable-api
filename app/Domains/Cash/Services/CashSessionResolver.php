<?php

namespace App\Domains\Cash\Services;

use App\Domains\Cash\Models\CashSession;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\EmissionPoint;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * Every invoice is charged in an open cash register. The session fixes the establishment, the emission
 * point and the warehouse of the sale, and receives its cash.
 */
final class CashSessionResolver
{
    /** An open session of the company, chosen by its public id. */
    public function open(Company $company, string $publicId): CashSession
    {
        $session = CashSession::query()->where('company_id', $company->id)->where('public_id', $publicId)->first();
        if (! $session) {
            throw new ApiException(ApiErrorCode::CASH_SESSION_NOT_FOUND, 'La caja no existe en la empresa actual.', 404);
        }
        if ($session->status !== CashSession::OPEN) {
            throw new ApiException(ApiErrorCode::CASH_SESSION_NOT_OPEN, 'La caja está cerrada; ábrela para vender.', 409);
        }

        return $session;
    }

    /** The open session of an emission point (the cash register of a module invoice). */
    public function openAt(Company $company, EmissionPoint $point): CashSession
    {
        $session = CashSession::query()->where('company_id', $company->id)->where('emission_point_id', $point->id)->where('status', CashSession::OPEN)->first();
        if (! $session) {
            $code = $point->establishment->code.'-'.$point->code;
            throw new ApiException(ApiErrorCode::CASH_SESSION_NOT_OPEN, "La caja {$code} no está abierta; ábrela en FACSTO para facturar.", 409);
        }

        return $session;
    }

    /** The register cash is given back from: the one chosen, else the invoice's while open, else the user's only one. */
    public function forRefund(Company $company, ?string $publicId, ?Sale $sale, User $user): CashSession
    {
        if ($publicId !== null) {
            return $this->open($company, $publicId);
        }

        return $this->forSale($company, $sale ?? new Sale, $user);
    }

    /**
     * The session a sale is invoiced in: its own while open; without one, the only open session the user
     * opened. Anything else asks the user to choose (or open) a cash register.
     */
    public function forSale(Company $company, Sale $sale, User $user): CashSession
    {
        if ($sale->cash_session_id !== null) {
            $session = CashSession::query()->whereKey($sale->cash_session_id)->first();
            if ($session?->status === CashSession::OPEN) {
                return $session;
            }
        }
        $mine = CashSession::query()->where('company_id', $company->id)->where('opened_by', $user->id)->where('status', CashSession::OPEN)->limit(2)->get();
        if ($mine->count() === 1) {
            return $mine->first();
        }

        throw new ApiException(ApiErrorCode::CASH_SESSION_REQUIRED, 'Abre o elige una caja para facturar esta venta.', 409);
    }
}
