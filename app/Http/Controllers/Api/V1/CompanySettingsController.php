<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\CompanySetting;
use App\Domains\Companies\Services\CompanySettings;
use App\Domains\Inventory\Models\Unit;
use App\Domains\Taxes\Enums\FiscalRuleCode;
use App\Domains\Taxes\Models\CompanyTax;
use App\Domains\Taxes\Services\FiscalRuleResolver;
use App\Http\Controllers\Controller;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sistema: every member reads the settings (the sale and product screens use the defaults); only owner
 * and admin change them, and each change is audited.
 */
final class CompanySettingsController extends Controller
{
    public function __construct(private readonly CompanySettings $settings, private readonly FiscalRuleResolver $rules) {}

    public function show(CompanyContext $context): JsonResponse
    {
        $company = $context->company();
        $this->authorize('view', $company);

        return response()->json(['data' => $this->present($this->settings->for($company))]);
    }

    public function update(Request $request, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $company = $context->company();
        $this->authorize('update', $company);
        $data = $request->validate([
            'lowStockThreshold' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'allowNegativeStock' => ['sometimes', 'boolean'],
            'defaultPaymentMethod' => ['sometimes', 'nullable', 'string', 'size:2'],
            'defaultCustomer' => ['sometimes', Rule::in(CompanySetting::DEFAULT_CUSTOMERS)],
            'defaultTaxPublicId' => ['sometimes', 'nullable', 'uuid'],
            'defaultUnit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'idleTimeoutMinutes' => ['sometimes', 'integer', Rule::in(CompanySetting::IDLE_TIMEOUTS)],
            'salesInterface' => ['sometimes', Rule::in(['LIST', 'GRID'])],
            'proformasInterface' => ['sometimes', Rule::in(['LIST', 'GRID'])],
            'investmentsInterface' => ['sometimes', Rule::in(['LIST', 'GRID'])],
            'moduleInterfaces' => ['sometimes', 'array'],
            'moduleInterfaces.*' => ['sometimes', Rule::in(['LIST', 'GRID'])],
        ]);

        $setting = $this->settings->for($company);
        $before = $this->present($setting);
        $map = [
            'lowStockThreshold' => 'low_stock_threshold',
            'allowNegativeStock' => 'allow_negative_stock',
            'defaultCustomer' => 'default_customer',
            'idleTimeoutMinutes' => 'idle_timeout_minutes',
            'salesInterface' => 'sales_interface',
            'proformasInterface' => 'proformas_interface',
            'investmentsInterface' => 'investments_interface',
        ];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $setting->{$column} = $data[$input];
            }
        }
        if (array_key_exists('moduleInterfaces', $data)) {
            $allowedModules = ['dashboard', 'cashRegister', 'sales', 'proformas', 'investments', 'receivables', 'payables', 'customers', 'suppliers', 'users', 'roles', 'warehouses', 'categories', 'brands', 'inventory', 'stock', 'retentions', 'sri'];
            $unknownModules = array_diff(array_keys($data['moduleInterfaces']), $allowedModules);
            if ($unknownModules !== []) {
                throw ValidationException::withMessages(['moduleInterfaces' => ['La configuración incluye un módulo no disponible.']]);
            }
            $setting->module_interfaces = array_merge($setting->module_interfaces ?? [], $data['moduleInterfaces']);
        }
        if (array_key_exists('defaultPaymentMethod', $data)) {
            $code = $data['defaultPaymentMethod'];
            if ($code !== null && ! FiscalPaymentMethod::query()->where('code', $code)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['defaultPaymentMethod' => ['La forma de pago no está vigente en el SRI.']]);
            }
            $setting->default_payment_method = $code;
        }
        if (array_key_exists('defaultTaxPublicId', $data)) {
            $tax = $data['defaultTaxPublicId'] === null ? null
                : CompanyTax::query()->where('company_id', $company->id)->where('public_id', $data['defaultTaxPublicId'])->where('is_active', true)->first();
            if ($data['defaultTaxPublicId'] !== null && ! $tax) {
                throw ValidationException::withMessages(['defaultTaxPublicId' => ['El IVA no está activo en la empresa.']]);
            }
            $setting->default_company_tax_id = $tax?->id;
        }
        if (array_key_exists('defaultUnit', $data)) {
            $unit = $data['defaultUnit'] === null ? null : trim($data['defaultUnit']);
            Unit::ensureDefaults($company->id);
            if ($unit !== null && ! Unit::query()->where('company_id', $company->id)->where('name', $unit)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['defaultUnit' => ['La unidad no existe en la empresa.']]);
            }
            $setting->default_unit = $unit;
        }
        $setting->company_id = $company->id;
        $setting->save();

        $after = $this->present($setting->refresh());
        $audit->record($request->user(), $company, 'company.settings.updated', $company, $before, $after);

        return response()->json(['data' => $after]);
    }

    private function present(CompanySetting $setting): array
    {
        return [
            'lowStockThreshold' => $setting->low_stock_threshold,
            'allowNegativeStock' => (bool) $setting->allow_negative_stock,
            'defaultPaymentMethod' => $setting->default_payment_method,
            'defaultCustomer' => $setting->default_customer,
            'defaultTaxPublicId' => $setting->defaultTax?->public_id,
            'defaultUnit' => $setting->default_unit,
            'idleTimeoutMinutes' => $setting->idle_timeout_minutes,
            'salesInterface' => $setting->sales_interface,
            'proformasInterface' => $setting->proformas_interface,
            'investmentsInterface' => $setting->investments_interface,
            'moduleInterfaces' => array_merge(array_fill_keys(['dashboard', 'cashRegister', 'sales', 'proformas', 'investments', 'receivables', 'payables', 'customers', 'suppliers', 'users', 'roles', 'warehouses', 'categories', 'brands', 'inventory', 'stock', 'retentions', 'sri'], 'LIST'), $setting->module_interfaces ?? []),
            // Read only: the SRI's global rule for today, so the sale screen never hard-codes it.
            'finalConsumerLimit' => $this->finalConsumerLimit(),
        ];
    }

    private function finalConsumerLimit(): ?string
    {
        try {
            return $this->rules->decimalAt(FiscalRuleCode::FINAL_CONSUMER_MAX_TOTAL, today());
        } catch (RuntimeException) {
            return null;
        }
    }
}
