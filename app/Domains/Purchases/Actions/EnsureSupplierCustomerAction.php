<?php

namespace App\Domains\Purchases\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Customers\Services\CustomerIdentificationService;
use App\Domains\Purchases\Models\Supplier;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** Ensures every supplier also has a customer-directory record for the same company and identification. */
final class EnsureSupplierCustomerAction
{
    public function __construct(
        private readonly CustomerIdentificationService $identifications,
        private readonly AuditRecorder $audit,
    ) {}

    public function execute(Company $company, Supplier $supplier, User $actor): Customer
    {
        if ($supplier->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::SUPPLIER_NOT_FOUND, 'El proveedor no existe en la empresa actual.', 404);
        }

        $type = $this->identifications->detect($supplier->identification);
        if (! in_array($type, [IdentificationType::RUC, IdentificationType::CEDULA], true)) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'La identificación del proveedor debe ser una cédula o un RUC válido.', 422);
        }

        return DB::transaction(function () use ($company, $supplier, $actor, $type): Customer {
            $customer = Customer::query()
                ->where('company_id', $company->id)
                ->where('identification_type', $type->value)
                ->where('identification', $supplier->identification)
                ->first();

            if ($customer) {
                return $customer;
            }

            $customer = $company->customers()->create([
                'identification_type' => $type->value,
                'identification' => $supplier->identification,
                'legal_name' => $supplier->legal_name,
                'commercial_name' => null,
                'email' => $supplier->email,
                'additional_emails' => [],
                'phone' => $supplier->phone,
                'address' => $supplier->address,
                'is_active' => true,
            ]);
            $this->audit->record($actor, $company, 'customer.created', $customer, null, $customer->only(['identification_type', 'identification', 'legal_name', 'commercial_name', 'email', 'phone', 'address', 'is_active']));

            return $customer;
        });
    }
}
