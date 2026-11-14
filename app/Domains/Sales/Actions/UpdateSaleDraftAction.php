<?php

namespace App\Domains\Sales\Actions;

use App\Domains\Cash\Models\CashMovement;
use App\Domains\Cash\Services\SaleCashRecorder;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Sales\DTOs\SaleDraftData;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleDraftWriter;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class UpdateSaleDraftAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly SaleDraftWriter $writer,
        private readonly SaleCashRecorder $cash,
    ) {}

    public function execute(Sale $sale, SaleDraftData $data, User $actor): Sale
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($sale, $data, $actor, $company): Sale {
            $sale = $company->sales()->whereKey($sale->id)->lockForUpdate()->first();
            if (! $sale) {
                throw new ApiException(ApiErrorCode::SALE_NOT_FOUND, 'El borrador no existe en la empresa activa.', 404);
            }
            if (! in_array($sale->status, [SaleStatus::DRAFT, SaleStatus::IN_CORRECTION], true)) {
                throw new ApiException(ApiErrorCode::SALE_NOT_EDITABLE, 'Sólo se pueden editar borradores activos.', 409);
            }

            $updated = $this->writer->update($sale, $data->toArray(), $actor);
            // A draft already paid «por facturar» keeps its cash in step with its new payments.
            if (CashMovement::query()->where('sale_id', $updated->id)->where('source', CashMovement::SALE)->whereNull('electronic_document_id')->exists()) {
                $this->cash->recordDraft($updated->load('paymentMethods'), $actor);
            }

            return $updated;
        });
    }
}
