<?php

namespace App\Domains\Taxes\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Taxes\Models\CompanyTax;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

final class DeactivateCompanyTaxAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(CompanyTax $tax, User $actor): CompanyTax
    {
        // Products would be left without a usable rate; they must be moved first with the bulk replacement.
        $itemCount = $tax->items()->count();
        if ($itemCount > 0) {
            throw new ApiException(ApiErrorCode::COMPANY_TAX_IN_USE, 'La tarifa está asignada a productos. Reemplázala primero por otra tarifa.', 409, ['itemCount' => $itemCount]);
        }

        $tax->forceFill(['is_active' => false])->save();
        $this->audit->record($actor, $this->context->company(), 'company_tax.deactivated', $tax, ['is_active' => true], ['is_active' => false]);

        return $tax->load('definition');
    }
}
