<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Actions\SaveServiceAction;
use App\Domains\Carwash\Actions\SaveServicePriceAction;
use App\Domains\Carwash\Actions\ServiceImageAction;
use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\ServicePrice;
use App\Http\Resources\Api\V1\Carwash\ServicePriceResource;
use App\Http\Resources\Api\V1\Carwash\ServiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Global services (Lavada Express) and their prices per vehicle specification, with the supplies they use. */
final class ServiceController extends CarwashController
{
    private const PRICE_WITH = ['service', 'spec.brand', 'spec.type', 'item.taxes.definition', 'supplies.item'];

    private const AMOUNT = 'regex:/^\d{1,12}(\.\d{1,6})?$/';

    public function index(Request $request): AnonymousResourceCollection
    {
        $includeInactive = $request->boolean('includeInactive');

        return ServiceResource::collection(Service::query()
            ->where('company_id', $this->companyId())
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->with(['category', 'tax', 'prices' => fn ($prices) => $prices
                ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
                ->with(['service', 'spec.brand', 'spec.type', 'item.taxes.definition'])])
            ->withCount('prices')
            ->orderBy('name')
            ->get());
    }

    public function show(string $service): ServiceResource
    {
        return new ServiceResource($this->owned(Service::class, $service)->load(['category', 'tax', 'prices' => fn ($prices) => $prices->with(self::PRICE_WITH)])->loadCount('prices'));
    }

    public function store(Request $request, SaveServiceAction $save): JsonResponse
    {
        $service = $save->execute(new Service, $this->serviceData($request, true), $request->user());

        return (new ServiceResource($service->load(['category', 'tax'])->loadCount('prices')))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $service, SaveServiceAction $save): ServiceResource
    {
        $saved = $save->execute($this->owned(Service::class, $service), $this->serviceData($request, false), $request->user());

        return $this->show($saved->public_id);
    }

    /** GET /carwash/services/{service}/image — the private photo, only for members of the company. */
    public function image(string $service): StreamedResponse
    {
        $entry = $this->owned(Service::class, $service);
        abort_unless($entry->image_path && Storage::disk('private')->exists($entry->image_path), 404);

        return Storage::disk('private')->response($entry->image_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function updateImage(Request $request, string $service, ServiceImageAction $images): ServiceResource
    {
        $image = $request->validate(
            ['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:max_width=4096,max_height=4096', 'max:3072']],
            ['image.max' => 'La foto no puede superar 3 MB.', 'image.mimes' => 'Usa una foto PNG, JPG o WebP.'],
        )['image'];
        $images->store($this->owned(Service::class, $service), $image, $request->user());

        return $this->show($service);
    }

    public function deleteImage(Request $request, string $service, ServiceImageAction $images): ServiceResource
    {
        $images->delete($this->owned(Service::class, $service), $request->user());

        return $this->show($service);
    }

    public function storePrice(Request $request, string $service, SaveServicePriceAction $save): JsonResponse
    {
        $data = $request->validate([
            'vehicleSpecPublicId' => ['required', 'uuid'],
            'salePrice' => ['required', 'string', self::AMOUNT],
            'priceIncludesTax' => ['sometimes', 'boolean'],
            ...$this->priceRules(),
        ]);
        $price = $save->create($this->owned(Service::class, $service), $data, $request->user());

        return (new ServicePriceResource($price->load(self::PRICE_WITH)))->response()->setStatusCode(201);
    }

    public function updatePrice(Request $request, string $price, SaveServicePriceAction $save): ServicePriceResource
    {
        $data = $request->validate([
            'salePrice' => ['sometimes', 'string', self::AMOUNT],
            'priceIncludesTax' => ['sometimes', 'boolean'],
            ...$this->priceRules(),
        ]);

        return new ServicePriceResource($save->update($this->owned(ServicePrice::class, $price), $data, $request->user())->load(self::PRICE_WITH));
    }

    /** @return array<string, list<string>> */
    private function priceRules(): array
    {
        return [
            'durationMinutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:1440'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'isActive' => ['sometimes', 'boolean'],
            'supplies' => ['sometimes', 'array', 'max:20'],
            'supplies.*.itemPublicId' => ['required', 'uuid', 'distinct'],
            'supplies.*.quantity' => ['required', 'string', self::AMOUNT, 'not_regex:/^0+(\.0+)?$/'],
            'supplies.*.measure' => ['nullable', 'in:ml,L,g,kg'],
        ];
    }

    /** @return array<string, mixed> */
    private function serviceData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'categoryPublicId' => ['sometimes', 'nullable', 'uuid'],
            'taxPublicId' => [$required, 'uuid'],
            'durationMinutes' => [$required, 'integer', 'min:5', 'max:1440'],
            'isFeatured' => ['sometimes', 'boolean'],
            'isBookable' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
        ]);
    }
}
