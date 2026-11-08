<?php

namespace App\Domains\Modules\Services;

use App\Domains\Companies\Models\Company;
use App\Domains\Modules\Enums\EntitlementStatus;
use App\Domains\Modules\Models\CompanyEntitlement;

/**
 * The module manifest (config/modules.php) and what each company may use. Entitlements are read once
 * per request; FACSTO never asks Platform on a request, it works with the last copy Platform sent.
 */
final class ModuleRegistry
{
    /** @var array<int, array<string, EntitlementStatus>> */
    private array $statuses = [];

    /** @return array<string, array{name: string, features: array<string, string>, permissions: array<string, string>, read_actions?: list<string>}> */
    public function manifest(): array
    {
        return (array) config('modules.modules', []);
    }

    public function exists(string $module): bool
    {
        return isset($this->manifest()[$module]);
    }

    /** @return list<string> every sellable feature key, across modules */
    public function featureKeys(): array
    {
        $keys = [];
        foreach ($this->manifest() as $module) {
            $keys = [...$keys, ...array_keys($module['features'])];
        }

        return $keys;
    }

    /** "carwash.storefront" → "carwash"; the module key of a feature. */
    public function moduleOf(string $feature): ?string
    {
        foreach ($this->manifest() as $key => $module) {
            if (isset($module['features'][$feature])) {
                return $key;
            }
        }

        return null;
    }

    /** ENABLED, READ_ONLY or DISABLED for the company, considering expiry and the parent module. */
    public function status(Company $company, string $feature): EntitlementStatus
    {
        $this->statuses[$company->id] ??= CompanyEntitlement::query()
            ->where('company_id', $company->id)
            ->get()
            ->mapWithKeys(fn (CompanyEntitlement $entitlement) => [$entitlement->feature => $entitlement->effectiveStatus()])
            ->all();
        $status = $this->statuses[$company->id][$feature] ?? EntitlementStatus::DISABLED;
        $module = $this->moduleOf($feature);
        if ($module !== null && $module !== $feature && $status !== EntitlementStatus::DISABLED) {
            $parent = $this->status($company, $module);
            if ($parent !== EntitlementStatus::ENABLED) {
                return $parent;
            }
        }

        return $status;
    }

    public function forget(Company $company): void
    {
        unset($this->statuses[$company->id]);
    }

    /**
     * Modules the company can open, for FACSTO Web: status, enabled features and the module actions the user's
     * permissions («module.action», CompanyAccess) allow. In READ_ONLY only the module's read_actions remain.
     *
     * @param  list<string>  $permissions
     * @return list<array{module: string, name: string, status: string, features: list<string>, can: list<string>}>
     */
    public function forCompany(Company $company, array $permissions): array
    {
        $modules = [];
        foreach ($this->manifest() as $key => $module) {
            $status = $this->status($company, $key);
            if ($status === EntitlementStatus::DISABLED) {
                continue;
            }
            $modules[] = [
                'module' => $key,
                'name' => $module['name'],
                'status' => $status->value,
                'features' => array_values(array_filter(
                    array_keys($module['features']),
                    fn (string $feature) => $this->status($company, $feature) !== EntitlementStatus::DISABLED,
                )),
                'can' => array_values(array_filter(
                    array_keys($module['permissions']),
                    fn (string $action) => in_array("{$key}.{$action}", $permissions, true)
                        && ($status === EntitlementStatus::ENABLED || in_array($action, $module['read_actions'] ?? ['view'], true)),
                )),
            ];
        }

        return $modules;
    }
}
