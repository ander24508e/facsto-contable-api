<?php

namespace App\Domains\Customers\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Models\Customer;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class DeactivateCustomerAction
{
    public function __construct(private readonly CompanyContext $companyContext, private readonly AuditRecorder $audit) {}

    public function execute(Customer $customer, User $actor): Customer
    {
        if ($customer->company_id !== $this->companyContext->company()->id) {
            throw new ApiException(ApiErrorCode::CUSTOMER_NOT_FOUND, 'El cliente no existe en la empresa activa.', 404);
        }

        return DB::transaction(function () use ($customer, $actor): Customer {
            $before = $customer->only(['is_active']);
            $customer->forceFill(['is_active' => false])->save();
            $customer->refresh();
            $this->audit->record($actor, $this->companyContext->company(), 'customer.deactivated', $customer, $before, $customer->only(['is_active']));

            return $customer;
        });
    }
}
