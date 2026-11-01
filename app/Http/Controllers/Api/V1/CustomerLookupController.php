<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Customers\Services\CustomerIdentificationService;
use App\Domains\Sri\Services\SriTaxpayerDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /customers/lookup?identification= — while typing a cédula or RUC in a sale: which type it is,
 * whether it is valid, the customer that already has it and the same person under the other type
 * (cédula ↔ RUC 001), so a duplicate is not created. A new RUC or cédula also brings the legal name
 * registered in the SRI's public catastro, when it answers.
 */
final class CustomerLookupController extends Controller
{
    public function __invoke(Request $request, CompanyContext $context, CustomerIdentificationService $identifications, SriTaxpayerDirectory $taxpayers): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);
        $identification = strtoupper(trim((string) $request->validate([
            'identification' => ['required', 'string', 'max:20'],
        ])['identification']));

        $type = $identifications->detect($identification);
        $related = match ($type) {
            IdentificationType::CEDULA => [$identification.'001'],
            IdentificationType::RUC => (int) $identification[2] <= 5 ? [substr($identification, 0, 10)] : [],
            default => [],
        };
        $customers = $context->company()->customers()
            ->whereIn('identification', [$identification, ...$related])
            ->orderByDesc('is_active')
            ->get();
        $match = $customers->firstWhere('identification', $identification);

        return response()->json(['data' => [
            'identification' => $identification,
            'detectedType' => $type?->value,
            'valid' => $type !== null,
            'customer' => $match ? new CustomerResource($match) : null,
            'sriTaxpayer' => ! $match && in_array($type, [IdentificationType::RUC, IdentificationType::CEDULA], true) ? $taxpayers->find($identification) : null,
            'relatedCustomers' => CustomerResource::collection($customers->where('identification', '<>', $identification)->values()),
        ]]);
    }
}
