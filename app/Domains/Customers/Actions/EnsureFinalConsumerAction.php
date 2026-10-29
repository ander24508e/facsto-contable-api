<?php

namespace App\Domains\Customers\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Customers\Services\CustomerIdentificationService;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/** The company's "CONSUMIDOR FINAL" (Tabla 6, code 07): created once, reactivated if needed, reused by every quick sale. */
final class EnsureFinalConsumerAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(User $actor): Customer
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($company, $actor): Customer {
            $customer = $company->customers()->firstOrCreate(
                ['identification_type' => IdentificationType::FINAL_CONSUMER->value, 'identification' => CustomerIdentificationService::FINAL_CONSUMER_IDENTIFICATION],
                ['legal_name' => 'CONSUMIDOR FINAL', 'is_active' => true],
            );
            if ($customer->wasRecentlyCreated) {
                $this->audit->record($actor, $company, 'customer.created', $customer, null, $customer->only(['identification_type', 'identification', 'legal_name']));
            } elseif (! $customer->is_active) {
                $customer->forceFill(['is_active' => true])->save();
            }

            return $customer;
        });
    }
}
