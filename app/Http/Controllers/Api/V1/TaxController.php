<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Taxes\Actions\ActivateCompanyTaxAction;
use App\Domains\Taxes\Actions\DeactivateCompanyTaxAction;
use App\Domains\Taxes\Actions\ReplaceCompanyTaxOnItemsAction;
use App\Domains\Taxes\Actions\UpdateCompanyTaxAction;
use App\Domains\Taxes\Models\CompanyTax;
use App\Domains\Taxes\Models\FiscalRule;
use App\Domains\Taxes\Models\SriTaxDefinition;
use App\Domains\Taxes\Enums\TaxCalculationType;
use App\Domains\Taxes\Enums\TaxType;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\Taxes\ActivateCompanyTaxRequest;
use App\Http\Requests\Taxes\ReplaceCompanyTaxRequest;
use App\Http\Requests\Taxes\UpdateCompanyTaxRequest;
use App\Http\Resources\Api\V1\FiscalRuleResource;
use App\Http\Resources\Api\V1\SriTaxDefinitionResource;
use App\Http\Resources\Api\V1\TaxResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TaxController extends Controller
{
    /** Taxes the company applies; `usable=1` returns only those a product can use today. */
    public function index(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompanyTax::class);
        $taxes = $context->company()->taxes()->with('definition')->get()
            ->sortBy(fn (CompanyTax $tax): string => $tax->definition->tax_type->value.str_pad((string) $tax->definition->fixed_percentage, 12, '0', STR_PAD_LEFT).$tax->definition->sri_percentage_code);
        if ($request->boolean('usable')) {
            $taxes = $taxes->filter(fn (CompanyTax $tax): bool => $tax->isUsableOn(today()));
        }
        if ($type = $request->query('tax_type')) {
            $taxes = $taxes->filter(fn (CompanyTax $tax): bool => $tax->definition->tax_type->value === $type);
        }

        return TaxResource::collection($taxes->values());
    }

    /** The official FACSTO catalog plus the IVA rates this company registered itself. */
    public function definitions(CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompanyTax::class);

        return SriTaxDefinitionResource::collection(
            SriTaxDefinition::query()->where('is_active', true)
                ->where(fn ($owner) => $owner->whereNull('company_id')->orWhere('company_id', $context->company()->id))
                ->orderBy('tax_type')->orderByDesc('valid_from')->orderBy('sri_percentage_code')->get(),
        );
    }

    /**
     * POST /tax-definitions — «Agregar IVA»: a rate the SRI decreed that the catalog does not have yet. It
     * belongs to the company, is applied at once and sends the SRI percentage code typed here, so it must
     * be the official one. A code already in force in the catalog is applied instead of duplicated.
     */
    public function storeDefinition(Request $request, CompanyContext $context, ActivateCompanyTaxAction $activate): JsonResponse
    {
        $this->authorize('manage', CompanyTax::class);
        $company = $context->company();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'percentageCode' => ['required', 'string', 'regex:/^\d{1,4}$/'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'validFrom' => ['required', 'date_format:Y-m-d'],
            'validUntil' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:validFrom'],
        ]);
        $inForce = SriTaxDefinition::query()->where('tax_type', TaxType::IVA->value)->where('sri_percentage_code', $data['percentageCode'])
            ->where('is_active', true)->where(fn ($owner) => $owner->whereNull('company_id')->orWhere('company_id', $company->id))
            ->where(fn ($valid) => $valid->whereNull('valid_until')->orWhereDate('valid_until', '>=', $data['validFrom']))->exists();
        if ($inForce) {
            throw new ApiException(ApiErrorCode::TAX_DEFINITION_EXISTS, 'Ese código de IVA ya está vigente en el catálogo: aplícalo desde «Aplicar una tarifa oficial».', 409);
        }

        $definition = DB::transaction(fn (): SriTaxDefinition => SriTaxDefinition::query()->create([
            'company_id' => $company->id, 'tax_type' => TaxType::IVA, 'sri_tax_code' => TaxType::IVA->sriCode(),
            'sri_percentage_code' => $data['percentageCode'], 'name' => mb_substr(trim($data['name']), 0, 60),
            'calculation_type' => TaxCalculationType::PERCENTAGE, 'fixed_percentage' => bcadd((string) $data['percentage'], '0', 6),
            'percentage_editable' => false, 'calculation_supported' => true, 'enabled_by_default' => false,
            'valid_from' => $data['validFrom'], 'valid_until' => $data['validUntil'] ?? null,
            'legal_basis' => 'Registrada por la empresa', 'is_active' => true,
        ]));
        $tax = $activate->execute($definition->public_id, null, null, $request->user());

        return (new TaxResource($tax))->response()->setStatusCode(201);
    }

    public function fiscalRules(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompanyTax::class);

        return FiscalRuleResource::collection(FiscalRule::query()->where('is_active', true)->orderBy('code')->orderByDesc('valid_from')->get());
    }

    public function activate(ActivateCompanyTaxRequest $request, ActivateCompanyTaxAction $activate): JsonResponse
    {
        $this->authorize('manage', CompanyTax::class);
        $tax = $activate->execute($request->validated('definitionPublicId'), $request->validated('validFrom'), $request->validated('validUntil'), $request->user());

        return (new TaxResource($tax))->response()->setStatusCode(201);
    }

    public function deactivate(Request $request, CompanyTax $companyTax, DeactivateCompanyTaxAction $deactivate): TaxResource
    {
        $this->authorize('update', $companyTax);

        return new TaxResource($deactivate->execute($companyTax, $request->user()));
    }

    public function update(UpdateCompanyTaxRequest $request, CompanyTax $companyTax, UpdateCompanyTaxAction $update): TaxResource
    {
        $this->authorize('update', $companyTax);

        return new TaxResource($update->execute($companyTax, $request->validated(), $request->user()));
    }

    public function previewReplacement(ReplaceCompanyTaxRequest $request, ReplaceCompanyTaxOnItemsAction $replace): JsonResponse
    {
        $this->authorize('manage', CompanyTax::class);
        $preview = $replace->preview($request->validated('fromPublicId'), $request->validated('toPublicId'), $request->validated('categoryPublicId'));

        return response()->json(['data' => [
            'from' => new TaxResource($preview['from']),
            'to' => new TaxResource($preview['to']),
            'itemCount' => $preview['itemCount'],
        ]]);
    }

    public function replace(ReplaceCompanyTaxRequest $request, ReplaceCompanyTaxOnItemsAction $replace): JsonResponse
    {
        $this->authorize('manage', CompanyTax::class);

        return response()->json(['data' => [
            'itemCount' => $replace->execute($request->validated('fromPublicId'), $request->validated('toPublicId'), $request->user(), $request->validated('categoryPublicId')),
        ]]);
    }
}
