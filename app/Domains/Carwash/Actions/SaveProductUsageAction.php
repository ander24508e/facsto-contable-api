<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\ServicePrice;
use App\Domains\Carwash\Models\ServiceSupply;
use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Services\ItemContent;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * A product of Tarifas (Brillo, caneca 20 L): how much of it each price per vehicle uses (500 ml in «Lavada
 * Universal · D-MAX»). It is the same data as the supplies of the price, seen from the product: one list, replaced.
 */
final class SaveProductUsageAction
{
    public function __construct(private readonly CompanyContext $context, private readonly ItemContent $content, private readonly AuditRecorder $audit) {}

    /** @param list<array{servicePricePublicId: string, quantity: string, measure?: string|null}> $usages */
    public function execute(Service $entry, array $usages, User $actor): void
    {
        $product = $entry->item;
        if ($product?->type !== ItemType::PRODUCT) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Sólo los productos se usan como insumo.', 422);
        }
        $rows = [];
        foreach (array_values($usages) as $index => $usage) {
            $price = ServicePrice::query()->where('company_id', $entry->company_id)->where('public_id', $usage['servicePricePublicId'])->first()
                ?? throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El precio por vehículo no existe.', 422, ['errors' => ["usages.$index.servicePricePublicId" => ['Elige un precio por vehículo.']]]);
            $measure = $usage['measure'] ?? null;
            if (! $this->content->accepts($product, $measure)) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, "«{$product->name}» no tiene contenido por unidad en esa medida.", 422, ['errors' => ["usages.$index.measure" => ['Pon el contenido por unidad del producto en Inventario o usa su propia unidad.']]]);
            }
            $rows[$price->id] = ['quantity' => bcadd((string) $usage['quantity'], '0', 6), 'measure' => $measure];
        }

        DB::transaction(function () use ($entry, $product, $rows, $actor): void {
            ServiceSupply::query()->where('item_id', $product->id)->whereNotIn('service_price_id', array_keys($rows))->delete();
            foreach ($rows as $priceId => $row) {
                ServiceSupply::query()->updateOrCreate(['service_price_id' => $priceId, 'item_id' => $product->id], [...$row, 'company_id' => $entry->company_id]);
            }
            $this->audit->record($actor, $this->context->company(), 'carwash.product_usage.updated', $entry, null, ['product' => $product->public_id, 'prices' => count($rows)]);
        });
    }
}
