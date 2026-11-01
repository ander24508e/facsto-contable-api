<?php

namespace App\Domains\Taxes\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Taxes\Models\CompanyTax;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class UpdateCompanyTaxAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(CompanyTax $tax, array $data, User $actor): CompanyTax
    {
        if ($tax->company_id !== $this->context->company()->id) {
            throw new ApiException(ApiErrorCode::TAX_NOT_AVAILABLE, 'La tarifa no pertenece a la empresa activa.', 404);
        }

        $definition = $tax->definition;
        $validFrom = $data['validFrom'] ?? null;
        $validUntil = $data['validUntil'] ?? null;
        if (($validFrom && $definition->valid_from->gt($validFrom))
            || ($validUntil && $definition->valid_from->gt($validUntil))
            || ($validUntil && $definition->valid_until?->lt($validUntil))) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'La vigencia debe estar dentro de las fechas oficiales de la tarifa.', 422);
        }

        return DB::transaction(function () use ($tax, $data, $actor): CompanyTax {
            $before = $tax->only(['name', 'valid_from', 'valid_until']);
            $tax->fill([
                'name' => trim($data['name']),
                'valid_from' => $data['validFrom'] ?? null,
                'valid_until' => $data['validUntil'] ?? null,
            ])->save();
            $this->audit->record($actor, $this->context->company(), 'company_tax.updated', $tax, $before, $tax->only(['name', 'valid_from', 'valid_until']));

            return $tax->load('definition');
        });
    }
}
