<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Actions\SaveNamedEntryAction;
use App\Domains\Carwash\Actions\SaveVehicleSpecAction;
use App\Domains\Carwash\Models\Vehicle;
use App\Domains\Carwash\Models\VehicleBrand;
use App\Domains\Carwash\Models\VehicleModel;
use App\Domains\Carwash\Models\VehicleType;
use App\Http\Resources\Api\V1\Carwash\NamedEntryResource;
use App\Http\Resources\Api\V1\Carwash\VehicleSpecResource;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Vehicle types (they set the price), brands and specifications (brand + model + type). */
final class VehicleCatalogController extends CarwashController
{
    public function types(Request $request): AnonymousResourceCollection
    {
        return NamedEntryResource::collection($this->listed(VehicleType::class, $request));
    }

    public function storeType(Request $request, SaveNamedEntryAction $save): NamedEntryResource
    {
        return new NamedEntryResource($save->execute(new VehicleType, $this->named($request, true), $request->user()));
    }

    public function updateType(Request $request, string $type, SaveNamedEntryAction $save): NamedEntryResource
    {
        $entry = $this->owned(VehicleType::class, $type);
        $data = $this->named($request, false);
        if (($data['is_active'] ?? true) === false && Vehicle::query()->where('vehicle_type_id', $entry->id)->where('is_active', true)->exists()) {
            throw new ApiException(ApiErrorCode::CARWASH_IN_USE, 'Hay vehículos activos de este tipo.', 409);
        }

        return new NamedEntryResource($save->execute($entry, $data, $request->user()));
    }

    public function brands(Request $request): AnonymousResourceCollection
    {
        return NamedEntryResource::collection($this->listed(VehicleBrand::class, $request));
    }

    public function storeBrand(Request $request, SaveNamedEntryAction $save): NamedEntryResource
    {
        return new NamedEntryResource($save->execute(new VehicleBrand, $this->named($request, true), $request->user()));
    }

    public function updateBrand(Request $request, string $brand, SaveNamedEntryAction $save): NamedEntryResource
    {
        return new NamedEntryResource($save->execute($this->owned(VehicleBrand::class, $brand), $this->named($request, false), $request->user()));
    }

    /** Specifications (brand + model + type) with how many vehicles use each. */
    public function specs(Request $request): AnonymousResourceCollection
    {
        return VehicleSpecResource::collection(VehicleModel::query()->with(['brand', 'type'])->withCount('vehicles')
            ->where('company_id', $this->companyId())
            ->when(! $request->boolean('includeInactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy(VehicleBrand::query()->select('name')->whereColumn('carwash_vehicle_brands.id', 'carwash_vehicle_models.vehicle_brand_id'))
            ->orderBy('name')
            ->get());
    }

    public function storeSpec(Request $request, SaveVehicleSpecAction $save): JsonResponse
    {
        $data = $request->validate([
            'brand' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'type' => ['required', 'string', 'max:80'],
        ]);

        return (new VehicleSpecResource($save->create($data, $request->user())))->response()->setStatusCode(201);
    }

    public function updateSpec(Request $request, string $spec, SaveVehicleSpecAction $save): VehicleSpecResource
    {
        $data = $request->validate([
            'model' => ['sometimes', 'string', 'max:80'],
            'type' => ['sometimes', 'string', 'max:80'],
            'isActive' => ['sometimes', 'boolean'],
        ]);
        $saved = $save->update($this->owned(VehicleModel::class, $spec), $data, $request->user());

        return new VehicleSpecResource($saved->loadCount('vehicles'));
    }

    /** @param class-string<Model> $model */
    private function listed(string $model, Request $request)
    {
        return $model::query()
            ->where('company_id', $this->companyId())
            ->when(! $request->boolean('includeInactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, mixed> */
    private function named(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:80'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        return array_filter([
            'name' => $data['name'] ?? null,
            'is_active' => array_key_exists('isActive', $data) ? (bool) $data['isActive'] : null,
        ], fn ($value) => $value !== null);
    }
}
