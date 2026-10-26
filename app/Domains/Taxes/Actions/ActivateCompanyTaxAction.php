<?php

namespace App\Domains\Taxes\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Taxes\Models\CompanyTax;
use App\Domains\Taxes\Models\SriTaxDefinition;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** The company applies an official rate; it only chooses an optional window inside the official validity. */
final class ActivateCompanyTaxAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(string $definitionPublicId, ?string $validFrom, ?string $validUntil, User $actor): CompanyTax
    {
        $company = $this->context->company();
        $definition = SriTaxDefinition::query()->where('public_id', $definitionPublicId)->where('is_active', true)->first();
        if (! $definition || ($definition->valid_until && $definition->valid_until->lt(today()))) {
            throw new ApiException(ApiErrorCode::TAX_NOT_AVAILABLE, 'La tarifa oficial no existe o ya no está vigente.', 422);
        }
        if (! $definition->calculation_supported) {
            throw new ApiException(ApiErrorCode::TAX_CALCULATION_NOT_SUPPORTED, 'FACSTO todavía no calcula este impuesto; no puede aplicarse a productos.', 422);
        }
        if (($validFrom && $definition->valid_from->gt($validFrom)) || ($validUntil && $definition->valid_until?->lt($validUntil))) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Las fechas deben estar dentro de la vigencia oficial de la tarifa.', 422);
        }

        return DB::transaction(function () use ($company, $definition, $validFrom, $validUntil, $actor): CompanyTax {
            $tax = $company->taxes()->firstOrNew(['sri_tax_definition_id' => $definition->id]);
            $before = $tax->exists ? $tax->only(['is_active', 'valid_from', 'valid_until']) : null;
            $tax->fill(['name' => $definition->name, 'valid_from' => $validFrom, 'valid_until' => $validUntil, 'is_active' => true])->save();
            $this->audit->record($actor, $company, 'company_tax.activated', $tax, $before, $tax->only(['is_active', 'valid_from', 'valid_until']));

            return $tax->load('definition');
        });
    }
}
