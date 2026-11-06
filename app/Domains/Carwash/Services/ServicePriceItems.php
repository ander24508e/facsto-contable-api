<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\ServicePrice;
use App\Domains\Carwash\Models\VehicleModel;
use App\Domains\Catalog\Actions\CreateItemAction;
use App\Domains\Catalog\Actions\UpdateItemAction;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Services\ItemCodeSuggester;
use App\Domains\Taxes\Services\ItemTaxService;
use App\Models\User;

/**
 * The Inventario item behind each price per vehicle: a SERVICE named «Lavada Express · Chevrolet Spark», with the
 * service's category and IVA and the price typed for that vehicle (same rule as an item: with or without IVA).
 * Ventas and the invoice use it as any other item. It is created and kept by the Lavadora (managed_by).
 */
final class ServicePriceItems
{
    public const MODULE = 'carwash';

    public function __construct(
        private readonly CompanyContext $context,
        private readonly CreateItemAction $create,
        private readonly UpdateItemAction $update,
        private readonly ItemTaxService $taxes,
        private readonly ItemCodeSuggester $codes,
    ) {}

    /** @param array{salePrice: string, priceIncludesTax: bool, isActive: bool} $price */
    public function create(Service $service, VehicleModel $spec, array $price, User $actor): Item
    {
        $name = $this->name($service, $spec);
        $item = $this->create->execute([
            'type' => 'SERVICE',
            'internal_code' => $this->codes->suggest($this->context->company(), $name, $service->category?->name),
            'name' => $name,
            'description' => $service->description,
            'unit' => 'UND',
            'sale_price' => $price['salePrice'],
            'price_includes_tax' => $price['priceIncludesTax'],
            'category_public_id' => $service->category?->public_id,
            'is_active' => $service->is_active && $price['isActive'],
        ], $actor);
        $item->forceFill(['managed_by' => self::MODULE])->save();
        $this->syncTaxes($item, $service);

        return $item;
    }

    /** @param array{salePrice?: string, priceIncludesTax?: bool} $changes */
    public function update(ServicePrice $price, array $changes, User $actor): void
    {
        $price->loadMissing(['service.category', 'service.tax', 'spec.brand', 'item']);
        $attributes = [
            'name' => $this->name($price->service, $price->spec),
            'description' => $price->service->description,
            'category_public_id' => $price->service->category?->public_id,
            'is_active' => $price->service->is_active && $price->is_active,
        ];
        if (array_key_exists('salePrice', $changes)) {
            $attributes['sale_price'] = $changes['salePrice'];
        }
        if (array_key_exists('priceIncludesTax', $changes)) {
            $attributes['price_includes_tax'] = $changes['priceIncludesTax'];
        }
        $this->update->execute($price->item, $attributes, $actor);
        $this->syncTaxes($price->item, $price->service);
    }

    /** A change of the service (name, category, IVA, on/off) reaches the items of all its prices. */
    public function refreshService(Service $service, User $actor): void
    {
        $service->load(['category', 'tax', 'prices.spec.brand', 'prices.item']);
        foreach ($service->prices as $price) {
            $price->setRelation('service', $service);
            $this->update($price, [], $actor);
        }
    }

    private function name(Service $service, VehicleModel $spec): string
    {
        $spec->loadMissing('brand');

        return mb_substr($service->name.' · '.$spec->brand->name.' '.$spec->name, 0, 60);
    }

    private function syncTaxes(Item $item, Service $service): void
    {
        $service->loadMissing('tax');
        $this->taxes->sync($item, $service->tax ? [$service->tax->public_id] : []);
    }
}
