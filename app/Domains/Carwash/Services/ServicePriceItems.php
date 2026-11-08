<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\ServicePrice;
use App\Domains\Carwash\Models\VehicleModel;
use App\Domains\Catalog\Actions\CreateItemAction;
use App\Domains\Catalog\Actions\UpdateItemAction;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Services\BarcodeGenerator;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Services\ItemCodeSuggester;
use App\Domains\Taxes\Services\ItemTaxService;
use App\Models\User;

/**
 * The Inventario item behind each price per vehicle: a SERVICE named «Lavada Universal · Chevrolet D-MAX» that copies
 * the base item's category, IVA (taxes) and «price includes IVA», with its own codes, cost and sale price. Ventas and
 * the invoice use it as any other item; the base item stays on sale with its own price. Created and kept by the
 * Lavadora (managed_by), through the same actions Inventario uses.
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
        private readonly BarcodeGenerator $barcodes,
    ) {}

    /** @param array<string, mixed> $data internalCode, auxiliaryCode, barcode, costPrice, salePrice, isActive */
    public function create(Service $service, VehicleModel $spec, array $data, User $actor): Item
    {
        $company = $this->context->company();
        $base = $service->item()->with(['category', 'taxes'])->firstOrFail();
        $name = $this->name($base, $spec);
        $item = $this->create->execute([
            'type' => 'SERVICE',
            'internal_code' => $this->text($data['internalCode'] ?? null) ?? $this->codes->suggest($company, $name, $base->category?->name),
            'auxiliary_code' => $this->text($data['auxiliaryCode'] ?? null),
            'barcode' => $this->text($data['barcode'] ?? null) ?? $this->barcodes->next($company),
            'name' => $name,
            'description' => $base->description,
            'unit' => 'UND',
            'sale_price' => $data['salePrice'],
            'cost_price' => $this->text($data['costPrice'] ?? null),
            'price_includes_tax' => (bool) $base->price_includes_tax,
            'category_public_id' => $base->category?->is_active ? $base->category->public_id : null,
            'is_active' => (bool) ($data['isActive'] ?? true),
        ], $actor);
        $item->forceFill(['managed_by' => self::MODULE])->save();
        $this->taxes->sync($item, $base->taxes->pluck('public_id')->all());

        return $item;
    }

    /** @param array<string, mixed> $changes internalCode, auxiliaryCode, barcode, costPrice, salePrice, isActive */
    public function update(ServicePrice $price, array $changes, User $actor): void
    {
        $map = ['internalCode' => 'internal_code', 'auxiliaryCode' => 'auxiliary_code', 'barcode' => 'barcode', 'costPrice' => 'cost_price', 'salePrice' => 'sale_price', 'isActive' => 'is_active'];
        $attributes = [];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $changes)) {
                $attributes[$column] = is_bool($changes[$key]) ? $changes[$key] : $this->text($changes[$key]);
            }
        }
        if (($attributes['internal_code'] ?? '') === null) {
            unset($attributes['internal_code']);
        }
        if ($attributes !== []) {
            $this->update->execute($price->item, $attributes, $actor);
        }
    }

    private function name(Item $base, VehicleModel $spec): string
    {
        $spec->loadMissing('brand');

        return mb_substr($base->name.' · '.$spec->brand->name.' '.$spec->name, 0, 60);
    }

    private function text(mixed $value): ?string
    {
        return trim((string) $value) ?: null;
    }
}
