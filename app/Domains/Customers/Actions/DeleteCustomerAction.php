<?php

namespace App\Domains\Customers\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Models\Customer;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Removes a customer for good. One with sales or fiscal documents is part of the fiscal history and
 * can only be deactivated.
 */
final class DeleteCustomerAction
{
    public function __construct(private readonly CompanyContext $companyContext, private readonly AuditRecorder $audit) {}

    public function execute(Customer $customer, User $actor): void
    {
        $company = $this->companyContext->company();
        if ($customer->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::CUSTOMER_NOT_FOUND, 'El cliente no existe en la empresa activa.', 404);
        }

        $inUse = Sale::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->exists()
            || ElectronicDocument::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->exists();
        if ($inUse) {
            throw new ApiException(ApiErrorCode::CUSTOMER_IN_USE, 'El cliente tiene ventas o comprobantes; solo puedes desactivarlo.', 409);
        }

        DB::transaction(function () use ($customer, $actor, $company): void {
            $before = $customer->only(['identification_type', 'identification', 'legal_name', 'is_active']);
            $customer->delete();
            $this->audit->record($actor, $company, 'customer.deleted', $customer, $before, []);
        });
    }
}
