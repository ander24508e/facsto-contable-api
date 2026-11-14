<?php

namespace App\Domains\Proformas\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Proformas\Models\Proforma;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class DeleteProformaAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(Proforma $proforma, User $actor): void
    {
        $company = $this->context->company();

        DB::transaction(function () use ($proforma, $actor, $company): void {
            $locked = Proforma::query()->where('company_id', $company->id)->whereKey($proforma->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new ApiException(ApiErrorCode::PROFORMA_NOT_FOUND, 'La proforma no existe en la empresa activa.', 404);
            }
            if ($locked->converted_sale_id !== null) {
                throw new ApiException(ApiErrorCode::PROFORMA_CONVERSION_PENDING, 'La proforma está vinculada a una venta en proceso de facturación.', 409);
            }

            if (StockMovement::query()->where('proforma_id', $locked->id)->exists() || $locked->status === Proforma::CONVERTED) {
                throw new ApiException(ApiErrorCode::PROFORMA_HAS_STOCK_HISTORY, 'La proforma ya movió stock o se convirtió en venta: recházala en lugar de eliminarla.', 409);
            }

            $this->audit->record($actor, $company, 'proforma.deleted', $locked, ['number' => $locked->displayNumber(), 'total' => $locked->total], []);
            $locked->delete();
        });
    }
}
