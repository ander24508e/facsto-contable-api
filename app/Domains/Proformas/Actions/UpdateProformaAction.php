<?php

namespace App\Domains\Proformas\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Proformas\Models\Proforma;
use App\Domains\Proformas\Services\ProformaWriter;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class UpdateProformaAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ProformaWriter $writer,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Proforma $proforma, array $data, User $actor): Proforma
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($proforma, $data, $actor, $company): Proforma {
            $locked = Proforma::query()->where('company_id', $company->id)->whereKey($proforma->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new ApiException(ApiErrorCode::PROFORMA_NOT_FOUND, 'La proforma no existe en la empresa activa.', 404);
            }
            if ($locked->converted_sale_id !== null) {
                throw new ApiException(ApiErrorCode::PROFORMA_CONVERSION_PENDING, 'Cancela la venta vinculada antes de editar esta proforma.', 409);
            }

            return $this->writer->save($locked, $data, $actor);
        });
    }
}
