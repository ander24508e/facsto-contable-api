<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Taxes\Actions\ActivateCompanyTaxAction;
use App\Domains\Taxes\Actions\DeactivateCompanyTaxAction;
use App\Domains\Taxes\Actions\ReplaceCompanyTaxOnItemsAction;
use App\Domains\Taxes\Models\CompanyTax;
use App\Domains\Taxes\Models\FiscalRule;
use App\Domains\Taxes\Models\SriTaxDefinition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Taxes\ActivateCompanyTaxRequest;
use App\Http\Requests\Taxes\ReplaceCompanyTaxRequest;
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

    /** Official FACSTO catalog (read-only for every company). */
    public function definitions(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompanyTax::class);

        return SriTaxDefinitionResource::collection(
            SriTaxDefinition::query()->where('is_active', true)->orderBy('tax_type')->orderByDesc('valid_from')->orderBy('sri_percentage_code')->get(),
        );
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
