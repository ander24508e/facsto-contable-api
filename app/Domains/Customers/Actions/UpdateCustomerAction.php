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

final class UpdateCustomerAction
{
    public function __construct(private readonly CompanyContext $companyContext, private readonly CustomerIdentificationService $identifications, private readonly AuditRecorder $audit) {}

    public function execute(Customer $customer, array $attributes, User $actor): Customer
    {
        $company = $this->companyContext->company();
        if ($customer->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::CUSTOMER_NOT_FOUND, 'El cliente no existe en la empresa activa.', 404);
        }

        $type = IdentificationType::from($attributes['identification_type'] ?? $customer->identification_type->value);
        $identification = $this->identifications->normalize($type, $attributes['identification'] ?? $customer->identification);
        if ($company->customers()->where('identification_type', $type->value)->where('identification', $identification)->where('id', '<>', $customer->id)->exists()) {
            throw new ApiException(ApiErrorCode::CUSTOMER_DUPLICATE, 'Ya existe un cliente con esta identificación en la empresa.', 409);
        }

        return DB::transaction(function () use ($customer, $attributes, $type, $identification, $actor, $company): Customer {
            $before = $customer->only(['identification_type', 'identification', 'legal_name', 'commercial_name', 'email', 'phone', 'address', 'is_active']);
            $customer->fill([...$attributes, 'identification_type' => $type, 'identification' => $identification])->save();
            $customer->refresh();
            $this->audit->record($actor, $company, 'customer.updated', $customer, $before, $customer->only(['identification_type', 'identification', 'legal_name', 'commercial_name', 'email', 'phone', 'address', 'is_active']));

            return $customer;
        });
    }
}
