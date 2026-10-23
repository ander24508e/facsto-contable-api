<?php

namespace App\Domains\Sales\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class CancelSaleDraftAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(Sale $sale, User $actor): Sale
    {
        $company = $this->context->company();
        if ($sale->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::SALE_NOT_FOUND, 'El borrador no existe en la empresa activa.', 404);
        }
        if ($sale->status !== SaleStatus::DRAFT) {
            throw new ApiException(ApiErrorCode::SALE_NOT_EDITABLE, 'Sólo se pueden cancelar borradores activos.', 409);
        }

        return DB::transaction(function () use ($sale, $actor, $company): Sale {
            $sale = $company->sales()->whereKey($sale->id)->lockForUpdate()->first();
            if (! $sale) {
                throw new ApiException(ApiErrorCode::SALE_NOT_FOUND, 'El borrador no existe en la empresa activa.', 404);
            }
            if ($sale->status !== SaleStatus::DRAFT) {
                throw new ApiException(ApiErrorCode::SALE_NOT_EDITABLE, 'Sólo se pueden cancelar borradores activos.', 409);
            }
            $before = ['status' => $sale->status->value];
            $sale->forceFill(['status' => SaleStatus::CANCELLED])->save();
            $this->audit->record($actor, $company, 'sale.draft_cancelled', $sale, $before, ['status' => SaleStatus::CANCELLED->value]);

            return $sale->refresh()->load(['customer', 'creator', 'details.taxes', 'paymentMethods']);
        });
    }
}
