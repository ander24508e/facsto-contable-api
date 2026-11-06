<?php

namespace App\Domains\Modules\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Modules\Enums\EntitlementSource;
use App\Domains\Modules\Enums\EntitlementStatus;
use App\Domains\Modules\Models\CompanyEntitlement;
use App\Domains\Modules\Models\CompanyEntitlementState;
use App\Domains\Modules\Models\EntitlementSync;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies the full entitlements state Platform sends for a company. It is a whole snapshot, not a delta:
 * a feature missing from the message is turned off. Each message carries an increasing version; an older
 * or repeated one changes nothing (Platform may resend or deliver out of order). Every message is logged.
 */
final class SyncCompanyEntitlementsAction
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @param  array{version: int, issuedAt?: string|null, subscriptionReference?: string|null, limits?: array<string, mixed>|null, entitlements: list<array{feature: string, status: string, validUntil?: string|null}>}  $data
     * @return array{applied: bool, version: int}
     */
    public function execute(Company $company, array $data, EntitlementSource $source = EntitlementSource::PLATFORM): array
    {
        $this->validate($data['entitlements']);

        return DB::transaction(function () use ($company, $data, $source): array {
            Company::query()->whereKey($company->id)->lockForUpdate()->first();
            $state = CompanyEntitlementState::query()->firstOrCreate(['company_id' => $company->id]);
            $applied = $data['version'] > $state->version;

            EntitlementSync::query()->create([
                'company_id' => $company->id,
                'version' => $data['version'],
                'source' => $source->value,
                'result' => $applied ? 'APPLIED' : 'STALE',
                'payload' => $data,
                'created_at' => now(),
            ]);
            if (! $applied) {
                return ['applied' => false, 'version' => $state->version];
            }

            $received = [];
            foreach ($data['entitlements'] as $entitlement) {
                $received[] = $entitlement['feature'];
                CompanyEntitlement::query()->updateOrCreate(
                    ['company_id' => $company->id, 'feature' => $entitlement['feature']],
                    [
                        'status' => EntitlementStatus::from($entitlement['status']),
                        'source' => $source,
                        'valid_until' => empty($entitlement['validUntil']) ? null : CarbonImmutable::parse($entitlement['validUntil']),
                    ],
                );
            }
            CompanyEntitlement::query()->where('company_id', $company->id)->whereNotIn('feature', $received)
                ->update(['status' => EntitlementStatus::DISABLED->value, 'source' => $source->value, 'updated_at' => now()]);

            $state->fill([
                'version' => $data['version'],
                'subscription_reference' => $data['subscriptionReference'] ?? null,
                'limits' => $data['limits'] ?? null,
                'issued_at' => empty($data['issuedAt']) ? null : CarbonImmutable::parse($data['issuedAt']),
                'synced_at' => now(),
            ])->save();
            $this->modules->forget($company);

            return ['applied' => true, 'version' => $data['version']];
        });
    }

    /** @param list<array{feature: string, status: string}> $entitlements */
    private function validate(array $entitlements): void
    {
        $known = $this->modules->featureKeys();
        $errors = [];
        $active = [];
        foreach ($entitlements as $index => $entitlement) {
            if (! in_array($entitlement['feature'], $known, true)) {
                $errors["entitlements.$index.feature"] = ['La función no existe en el manifiesto de módulos.'];
            }
            if ($entitlement['status'] !== EntitlementStatus::DISABLED->value) {
                $active[] = $entitlement['feature'];
            }
        }
        // A module feature (carwash.storefront) needs its module (carwash) in the same message.
        foreach ($active as $feature) {
            $module = $this->modules->moduleOf($feature);
            if ($module !== null && $module !== $feature && ! in_array($module, $active, true)) {
                $errors['entitlements'][] = "La función {$feature} requiere el módulo {$module}.";
            }
        }
        if ($errors !== []) {
            throw new ApiException(ApiErrorCode::ENTITLEMENTS_INVALID, 'Los derechos enviados no son válidos.', 422, ['errors' => $errors]);
        }
    }
}
