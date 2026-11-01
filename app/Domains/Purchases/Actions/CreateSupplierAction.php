<?php

namespace App\Domains\Purchases\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Purchases\Models\Supplier;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class CreateSupplierAction
{
    public function __construct(
        private readonly EnsureSupplierCustomerAction $ensureCustomer,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array{identification: string, legal_name: string, email: ?string, phone: ?string, address: ?string} $data */
    public function execute(Company $company, array $data, User $actor): Supplier
    {
        return DB::transaction(function () use ($company, $data, $actor): Supplier {
            if (Supplier::query()->where('company_id', $company->id)->where('identification', $data['identification'])->exists()) {
                throw new ApiException(ApiErrorCode::SUPPLIER_ALREADY_EXISTS, 'Ya existe un proveedor con esa identificación.', 409);
            }

            $supplier = Supplier::query()->create(['company_id' => $company->id, ...$data, 'is_active' => true]);
            $this->ensureCustomer->execute($company, $supplier, $actor);
            $this->audit->record($actor, $company, 'supplier.created', $supplier, null, $supplier->only(['identification', 'legal_name']));

            return $supplier;
        });
    }
}
