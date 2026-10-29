<?php

namespace App\Domains\Taxes\Services;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use App\Domains\Taxes\Enums\TaxType;
use App\Domains\Taxes\Models\CompanyTax;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Collection;

final class ItemTaxService
{
    /** @param list<string> $companyTaxPublicIds */
    public function sync(Item $item, array $companyTaxPublicIds): void
    {
        $taxes = $this->resolve($item->company, $companyTaxPublicIds);
        $item->taxes()->sync($taxes->mapWithKeys(fn ($tax): array => [$tax->id => ['company_id' => $item->company_id]])->all());
    }

    /** @param list<string> $companyTaxPublicIds */
    public function syncCategory(Category $category, array $companyTaxPublicIds): void
    {
        $taxes = $companyTaxPublicIds === [] ? collect() : $this->resolve($category->company, $companyTaxPublicIds);
        $category->taxes()->sync($taxes->mapWithKeys(fn ($tax): array => [$tax->id => ['company_id' => $category->company_id]])->all());
    }

    /** A new item of a category starts with the category's rates, when they are still valid. */
    public function inheritFromCategory(Item $item): void
    {
        $category = $item->category()->with('taxes.definition')->first();
        $taxes = $category?->taxes->filter(fn (CompanyTax $tax): bool => $tax->isUsableOn(today()));
        if ($taxes && $taxes->isNotEmpty() && $item->taxes()->doesntExist()) {
            $item->taxes()->sync($taxes->mapWithKeys(fn ($tax): array => [$tax->id => ['company_id' => $item->company_id]])->all());
        }
    }

    /**
     * @param  list<string>  $companyTaxPublicIds
     * @return Collection<int, CompanyTax>
     */
    private function resolve(Company $company, array $companyTaxPublicIds): Collection
    {
        $ids = array_values(array_unique($companyTaxPublicIds));
        $taxes = $company->taxes()->whereIn('public_id', $ids)->with('definition')->get()
            ->filter(fn ($tax): bool => $tax->isUsableOn(today()));

        if ($taxes->count() !== count($ids)) {
            throw new ApiException(ApiErrorCode::TAX_NOT_AVAILABLE, 'Una o más tarifas no están activas o vigentes en la empresa.', 422);
        }
        // Every SRI <detalle> carries exactly one IVA <impuesto> (0 %, no objeto, exento or a rate).
        if ($taxes->filter(fn ($tax): bool => $tax->definition->tax_type === TaxType::IVA)->count() !== 1) {
            throw new ApiException(ApiErrorCode::ITEM_IVA_REQUIRED, 'Cada artículo necesita exactamente una tarifa de IVA (puede ser 0 %, no objeto o exento).', 422);
        }

        return $taxes->values();
    }
}
