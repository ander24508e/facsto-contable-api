<?php

namespace App\Http\Controllers\Internal\V1;

use App\Domains\Companies\Models\Company;
use App\Domains\Modules\Actions\SyncCompanyEntitlementsAction;
use App\Domains\Modules\Models\CompanyEntitlement;
use App\Domains\Modules\Models\CompanyEntitlementState;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Condore Platform → FACSTO. PUT replaces the company's whole entitlements state (versioned, idempotent);
 * GET returns what FACSTO holds, so Platform can reconcile after an outage.
 */
final class PlatformEntitlementController extends Controller
{
    public function show(string $company): JsonResponse
    {
        return response()->json(['data' => $this->present($this->company($company))]);
    }

    public function update(Request $request, string $company, SyncCompanyEntitlementsAction $sync): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'issuedAt' => ['nullable', 'date'],
            'subscriptionReference' => ['nullable', 'string', 'max:120'],
            'limits' => ['nullable', 'array'],
            'entitlements' => ['present', 'array', 'max:200'],
            'entitlements.*.feature' => ['required', 'string', 'max:60', 'distinct'],
            'entitlements.*.status' => ['required', 'in:ENABLED,READ_ONLY,DISABLED'],
            'entitlements.*.validUntil' => ['nullable', 'date'],
        ]);
        $target = $this->company($company);
        $result = $sync->execute($target, $data);

        return response()->json(['data' => $this->present($target), 'meta' => $result]);
    }

    private function company(string $publicId): Company
    {
        return Company::query()->where('public_id', $publicId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(Company $company): array
    {
        $state = CompanyEntitlementState::query()->where('company_id', $company->id)->first();

        return [
            'companyPublicId' => $company->public_id,
            'version' => $state?->version ?? 0,
            'subscriptionReference' => $state?->subscription_reference,
            'limits' => $state?->limits,
            'syncedAt' => $state?->synced_at?->toIso8601String(),
            'entitlements' => CompanyEntitlement::query()->where('company_id', $company->id)->orderBy('feature')->get()
                ->map(fn (CompanyEntitlement $entitlement) => [
                    'feature' => $entitlement->feature,
                    'status' => $entitlement->status->value,
                    'effectiveStatus' => $entitlement->effectiveStatus()->value,
                    'source' => $entitlement->source,
                    'validUntil' => $entitlement->valid_until?->toIso8601String(),
                ])->values(),
        ];
    }
}
