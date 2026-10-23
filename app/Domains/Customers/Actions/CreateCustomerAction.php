<?php

namespace App\Domains\Customers\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Customers\Services\CustomerIdentificationService;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class CreateCustomerAction
{
    public function __construct(private readonly CompanyContext $companyContext, private readonly CustomerIdentificationService $identifications, private readonly AuditRecorder $audit) {}

    public function execute(array $attributes, User $actor): Customer
    {
        $company = $this->companyContext->company();
        $type = IdentificationType::from($attributes['identification_type']);
        $identification = $this->identifications->normalize($type, $attributes['identification']);

        if ($company->customers()->where('identification_type', $type->value)->where('identification', $identification)->exists()) {
            throw new ApiException(ApiErrorCode::CUSTOMER_DUPLICATE, 'Ya existe un cliente con esta identificación en la empresa.', 409);
        }

        return DB::transaction(function () use ($company, $attributes, $type, $identification, $actor): Customer {
            $customer = $company->customers()->create([...$attributes, 'identification_type' => $type, 'identification' => $identification]);
            $this->audit->record($actor, $company, 'customer.created', $customer, null, $customer->only(['identification_type', 'identification', 'legal_name', 'commercial_name', 'email', 'phone', 'address', 'is_active']));

            return $customer;
        });
    }
}
