<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Actions\SaveProductUsageAction;
use App\Domains\Carwash\Actions\SaveServicePriceAction;
use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\ServicePrice;
use App\Domains\Carwash\Services\AreaTypeCatalog;
use App\Domains\Carwash\Services\Rates;
use App\Domains\Companies\Context\CompanyContext;
use App\Http\Resources\Api\V1\Carwash\ServicePriceResource;
use App\Http\Resources\Api\V1\Carwash\ServiceResource;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Tarifas: the Inventario services and products of the categories turned on in the Lavadora. Services get prices
 * per vehicle with their supplies; products say how much each price per vehicle uses of them.
 */
final class ServiceController extends CarwashController
{
    private const ITEM_WITH = ['item.category', 'item.taxes.definition', 'item.catalogMetadata', 'areaType'];

    private const PRICE_WITH = ['service.item', 'spec.brand', 'spec.type', 'item.taxes.definition', 'item.catalogMetadata', 'supplies.item'];

    private const AMOUNT = 'regex:/^\d{1,12}(\.\d{1,6})?$/';

    /**
     * GET /carwash/services — Tarifas (default): items of the turned-on categories. ?for=orders: every service
     * with an active price, whatever its category (taking a category off only hides it from Tarifas).
     */
    public function index(Request $request, Rates $rates): AnonymousResourceCollection
    {
        $includeInactive = $request->boolean('includeInactive');
        $forOrders = $request->query('for') === 'orders';
        if (! $forOrders) {
            $rates->sync($this->companyId());
        }
        $query = $forOrders
            ? Service::query()->where('company_id', $this->companyId())->whereHas('prices', fn ($prices) => $prices->where('is_active', true))
            : $rates->visible($this->companyId());

        $services = $query
            ->when(! $includeInactive, fn ($builder) => $builder->whereHas('item', fn ($item) => $item->where('is_active', true)))
            ->with([...self::ITEM_WITH, 'prices' => fn ($prices) => $prices
                ->when(! $includeInactive, fn ($builder) => $builder->where('is_active', true))
                ->with(['service.item', 'spec.brand', 'spec.type', 'item.taxes.definition'])])
            ->withCount(['prices', 'usages'])
            ->get()
            ->sortBy(fn (Service $service): string => mb_strtolower((string) $service->item?->name))
            ->values();

        return ServiceResource::collection($services);
    }

    public function show(string $service): ServiceResource
    {
        return new ServiceResource($this->owned(Service::class, $service)
            ->load([
                ...self::ITEM_WITH,
                'prices' => fn ($prices) => $prices->with(self::PRICE_WITH),
                'usages' => fn ($usages) => $usages->with(['price.service.item', 'price.spec.brand', 'price.spec.type']),
            ])
            ->loadCount(['prices', 'usages']));
    }

    /** PATCH /carwash/services/{service} — only the base duration and the area type: the rest is the Inventario item's. */
    public function update(Request $request, string $service, CompanyContext $context, AuditRecorder $audit, AreaTypeCatalog $catalog): ServiceResource
    {
        $data = $request->validate([
            'durationMinutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'areaTypePublicId' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $entry = $this->owned(Service::class, $service);
        $present = fn (): array => ['durationMinutes' => $entry->duration_minutes, 'areaType' => $entry->areaType()->value('name')];
        $before = $present();
        $changes = [];
        if (array_key_exists('durationMinutes', $data)) {
            $changes['duration_minutes'] = $data['durationMinutes'];
        }
        if (array_key_exists('areaTypePublicId', $data)) {
            $changes['area_type_id'] = $data['areaTypePublicId'] === null ? null
                : $catalog->resolve($this->companyId(), [$data['areaTypePublicId']], 'areaTypePublicId', array_filter([$entry->area_type_id]))->first()->id;
        }
        $entry->forceFill($changes)->save();
        $audit->record($request->user(), $context->company(), 'carwash.service.updated', $entry, $before, $present());

        return $this->show($service);
    }

    /** PUT /carwash/services/{service}/usages — for a product: how much each price per vehicle uses of it. */
    public function usages(Request $request, string $service, SaveProductUsageAction $save): ServiceResource
    {
        $data = $request->validate([
            'usages' => ['present', 'array', 'max:200'],
            'usages.*.servicePricePublicId' => ['required', 'uuid', 'distinct'],
            'usages.*.quantity' => ['required', 'string', self::AMOUNT, 'not_regex:/^0+(\.0+)?$/'],
            'usages.*.measure' => ['nullable', 'in:ml,L,g,kg'],
        ]);
        $save->execute($this->owned(Service::class, $service)->load('item'), $data['usages'], $request->user());

        return $this->show($service);
    }

    public function storePrice(Request $request, string $service, SaveServicePriceAction $save): JsonResponse
    {
        $data = $request->validate([
            'vehicleSpecPublicId' => ['required', 'uuid'],
            'salePrice' => ['required', 'string', self::AMOUNT],
            ...$this->priceRules(),
        ]);
        $price = $save->create($this->owned(Service::class, $service)->load('item'), $data, $request->user());

        return (new ServicePriceResource($price->load(self::PRICE_WITH)))->response()->setStatusCode(201);
    }

    public function updatePrice(Request $request, string $price, SaveServicePriceAction $save): ServicePriceResource
    {
        $data = $request->validate([
            'salePrice' => ['sometimes', 'string', self::AMOUNT],
            ...$this->priceRules(),
        ]);

        return new ServicePriceResource($save->update($this->owned(ServicePrice::class, $price)->load('item'), $data, $request->user())->load(self::PRICE_WITH));
    }

    /** @return array<string, list<string>> */
    private function priceRules(): array
    {
        return [
            'internalCode' => ['sometimes', 'nullable', 'string', 'max:25'],
            'auxiliaryCode' => ['sometimes', 'nullable', 'string', 'max:25'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'costPrice' => ['sometimes', 'nullable', 'string', self::AMOUNT],
            'durationMinutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'isActive' => ['sometimes', 'boolean'],
            'supplies' => ['sometimes', 'array', 'max:20'],
            'supplies.*.itemPublicId' => ['required', 'uuid', 'distinct'],
            'supplies.*.quantity' => ['required', 'string', self::AMOUNT, 'not_regex:/^0+(\.0+)?$/'],
            'supplies.*.measure' => ['nullable', 'in:ml,L,g,kg'],
        ];
    }
}
