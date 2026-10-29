<?php

namespace App\Domains\Customers\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Models\Customer;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Enums\SaleStatus;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Joins a duplicated customer into the one that stays. Sales and documents point to the kept customer;
 * issued documents keep their frozen buyer data (they are fiscal records) and only open drafts take the
 * kept customer's data. The duplicate is deactivated, never deleted.
 */
final class MergeCustomersAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Customer $target, string $sourcePublicId, User $actor): Customer
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($company, $target, $sourcePublicId, $actor): Customer {
            $target = $company->customers()->whereKey($target->id)->lockForUpdate()->first();
            $source = $company->customers()->where('public_id', $sourcePublicId)->lockForUpdate()->first();
            if (! $target || ! $source) {
                throw new ApiException(ApiErrorCode::CUSTOMER_NOT_FOUND, 'Los dos clientes deben pertenecer a la empresa actual.', 404);
            }
            if ($target->id === $source->id) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Selecciona un cliente distinto para unir.', 422);
            }

            $sales = $company->sales()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
            $company->sales()->where('customer_id', $target->id)->where('status', SaleStatus::DRAFT->value)->update([
                'customer_identification_type' => $target->identification_type->value,
                'customer_identification' => $target->identification,
                'customer_name' => $target->legal_name,
                'customer_address' => $target->address,
                'customer_email' => $target->email,
            ]);
            $documents = ElectronicDocument::query()->where('company_id', $company->id)->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
            $source->forceFill(['is_active' => false])->save();

            $this->audit->record($actor, $company, 'customer.merged', $target, ['mergedPublicId' => $source->public_id], [
                'mergedIdentification' => $source->identification,
                'movedSales' => $sales,
                'movedDocuments' => $documents,
            ]);

            return $target->refresh();
        });
    }
}
