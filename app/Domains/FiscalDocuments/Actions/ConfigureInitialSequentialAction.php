<?php

namespace App\Domains\FiscalDocuments\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\EmissionPoint;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\DocumentSequence;
use App\Domains\FiscalDocuments\Services\DocumentSequenceStatus;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * "Definir secuencial inicial": the first invoice number of the current RUC in a point — 1 by default,
 * or the number that continues the numbering of another system. Only before that RUC numbers there.
 */
final class ConfigureInitialSequentialAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly DocumentSequenceStatus $status,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(EmissionPoint $point, int $initialSequential, User $actor): EmissionPoint
    {
        $company = $this->context->company();
        if ($point->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::EMISSION_POINT_NOT_FOUND, 'El punto de emisión no existe.', 404);
        }

        return DB::transaction(function () use ($company, $point, $initialSequential, $actor): EmissionPoint {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            if (blank($company->ruc)) {
                throw new ApiException(ApiErrorCode::ISSUER_CONFIGURATION_REQUIRED, 'Configura el RUC de la empresa antes de definir la numeración.', 409);
            }
            if ($this->status->hasDocuments($point, $company->ruc)) {
                throw new ApiException(ApiErrorCode::DOCUMENT_SEQUENCE_LOCKED, 'Este punto ya emitió comprobantes con el RUC actual: su numeración no se puede cambiar.', 409);
            }

            $sequence = DocumentSequence::query()->firstOrNew([
                'company_id' => $company->id,
                'establishment_id' => $point->establishment_id,
                'emission_point_id' => $point->id,
                'document_type' => DocumentType::INVOICE->value,
                'issuer_ruc' => $company->ruc,
            ]);
            $before = $sequence->exists ? ['initialSequential' => $sequence->initial_number] : null;
            $sequence->forceFill(['initial_number' => $initialSequential, 'current_number' => $initialSequential - 1, 'is_active' => true])->save();

            $this->audit->record($actor, $company, 'emission_point.initial_sequential_configured', $point, $before, [
                'issuerRuc' => $company->ruc,
                'initialSequential' => $initialSequential,
            ]);

            return $point->refresh();
        });
    }
}
