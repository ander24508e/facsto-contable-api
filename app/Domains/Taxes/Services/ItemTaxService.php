<?php

namespace App\Domains\Taxes\Services;

use App\Domains\Catalog\Models\Item;
use App\Domains\Taxes\Enums\TaxType;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

final class ItemTaxService
{
    /** @param list<string> $companyTaxPublicIds */
    public function sync(Item $item, array $companyTaxPublicIds): void
    {
        $ids = array_values(array_unique($companyTaxPublicIds));
        $taxes = $item->company->taxes()->whereIn('public_id', $ids)->with('definition')->get()
            ->filter(fn ($tax): bool => $tax->isUsableOn(today()));

        if ($taxes->count() !== count($ids)) {
            throw new ApiException(ApiErrorCode::TAX_NOT_AVAILABLE, 'Una o más tarifas no están activas o vigentes en la empresa.', 422);
        }
        // Every SRI <detalle> carries exactly one IVA <impuesto> (0 %, no objeto, exento or a rate).
        if ($taxes->filter(fn ($tax): bool => $tax->definition->tax_type === TaxType::IVA)->count() !== 1) {
            throw new ApiException(ApiErrorCode::ITEM_IVA_REQUIRED, 'Cada artículo necesita exactamente una tarifa de IVA (puede ser 0 %, no objeto o exento).', 422);
        }

        $item->taxes()->sync($taxes->mapWithKeys(fn ($tax): array => [$tax->id => ['company_id' => $item->company_id]])->all());
    }
}
