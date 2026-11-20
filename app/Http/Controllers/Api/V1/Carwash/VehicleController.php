<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Actions\SaveVehicleAction;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\Vehicle;
use App\Domains\Carwash\Models\VehicleBrand;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\PlateNormalizer;
use App\Http\Resources\Api\V1\Carwash\VehicleResource;
use App\Http\Resources\Api\V1\Carwash\WorkOrderResource;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class VehicleController extends CarwashController
{
    private const RELATIONS = ['customer', 'type', 'brand', 'model'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'customerPublicId' => ['nullable', 'uuid'],
            'brandPublicId' => ['nullable', 'uuid'],
            'typePublicId' => ['nullable', 'uuid'],
            // ACTIVE or INACTIVE; without it only the active ones, unless includeInactive asks for all.
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
            'includeInactive' => ['sometimes', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $plate = strtoupper((string) preg_replace('/[\s\-.]/', '', $search));
        $status = $filters['status'] ?? (($filters['includeInactive'] ?? false) ? null : 'ACTIVE');

        return VehicleResource::collection(Vehicle::query()
            ->with(self::RELATIONS)
            ->where('company_id', $this->companyId())
            ->when($status, fn ($query, $state) => $query->where('is_active', $state === 'ACTIVE'))
            ->when($filters['brandPublicId'] ?? null, fn ($query, $brand) => $query->whereHas('brand', fn ($owner) => $owner->where('public_id', $brand)))
            ->when($filters['typePublicId'] ?? null, fn ($query, $type) => $query->whereHas('type', fn ($owner) => $owner->where('public_id', $type)))
            ->when($filters['customerPublicId'] ?? null, fn ($query, $customer) => $query->whereHas('customer', fn ($owner) => $owner->where('public_id', $customer)))
            // One box finds by plate, brand, model or type (Vehículos is about the vehicle; the customer goes per order).
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('plate', 'like', '%'.$plate.'%')
                ->orWhereHas('brand', fn ($brand) => $brand->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%']))
                ->orWhereHas('model', fn ($model) => $model->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%']))
                ->orWhereHas('type', fn ($type) => $type->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%']))))
            ->orderBy('plate')
            ->paginate($filters['per_page'] ?? 15))
            ->additional(['meta' => ['summary' => $this->summary()]]);
    }

    /** The cards of Vehículos: company totals, whatever the filters. */
    private function summary(): array
    {
        $vehicles = Vehicle::query()->where('company_id', $this->companyId());

        return [
            'vehicles' => (clone $vehicles)->count(),
            'active' => (clone $vehicles)->where('is_active', true)->count(),
            'brands' => VehicleBrand::query()->where('company_id', $this->companyId())->where('is_active', true)->count(),
            'customersWithVehicle' => (clone $vehicles)->distinct()->count('customer_id'),
        ];
    }

    /** Reception: the plate typed at the counter returns the vehicle and its open order, if any. */
    public function lookup(Request $request, PlateNormalizer $plates): JsonResponse
    {
        $plate = $plates->normalize($request->validate(['plate' => ['required', 'string', 'max:20']])['plate']);
        $vehicle = Vehicle::query()->with(self::RELATIONS)->where('company_id', $this->companyId())->where('plate', $plate)->first()
            ?? throw new ApiException(ApiErrorCode::CARWASH_VEHICLE_NOT_FOUND, 'No hay un vehículo con esa placa.', 404, ['plate' => $plate]);
        $open = WorkOrder::query()->with(WorkOrderController::RELATIONS)
            ->where('vehicle_id', $vehicle->id)
            ->whereNotIn('status', [WorkOrderStatus::DELIVERED, WorkOrderStatus::CANCELLED])
            ->latest('id')
            ->first();

        return response()->json(['data' => [
            'vehicle' => new VehicleResource($vehicle),
            'openWorkOrder' => $open ? new WorkOrderResource($open) : null,
            'history' => $this->history($vehicle),
        ]]);
    }

    /**
     * «Historial» of Nueva agenda when the plate is found: its delivered visits (the last five with their services and
     * total) and the customer's loyalty counter (Configuración › Clientes «Fidelización»).
     *
     * @return array<string, mixed>
     */
    private function history(Vehicle $vehicle): array
    {
        $delivered = WorkOrder::query()->where('company_id', $vehicle->company_id)->where('vehicle_id', $vehicle->id)->where('status', WorkOrderStatus::DELIVERED);
        $settings = CarwashSetting::forCompany($vehicle->company_id);
        $loyalty = null;
        if ($settings->loyalty_every) {
            // The counter of the vehicle (whoever brought it), since the customer changes from order to order.
            $count = (clone $delivered)->count();
            $remaining = $settings->loyalty_every - ($count % $settings->loyalty_every);
            $loyalty = ['every' => (int) $settings->loyalty_every, 'delivered' => $count, 'remaining' => $remaining, 'nextIsFree' => $remaining === 1];
        }

        return [
            'visits' => (clone $delivered)->count(),
            'last' => (clone $delivered)->with(['lines', 'sale', 'customer'])->latest('delivered_at')->limit(5)->get()->map(fn (WorkOrder $order): array => [
                'publicId' => $order->public_id,
                'code' => $order->code(),
                'deliveredAt' => $order->delivered_at?->toIso8601String(),
                'services' => $order->lines->pluck('description')->values()->all(),
                'customer' => $order->contactName() ?: null,
                'total' => $order->sale ? (string) $order->sale->total : null,
            ])->all(),
            'loyalty' => $loyalty,
        ];
    }

    public function show(string $vehicle): VehicleResource
    {
        return new VehicleResource($this->owned(Vehicle::class, $vehicle)->load(self::RELATIONS));
    }

    public function store(Request $request, SaveVehicleAction $save): JsonResponse
    {
        return (new VehicleResource($save->execute(new Vehicle, $this->validated($request, true), $request->user())))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $vehicle, SaveVehicleAction $save): VehicleResource
    {
        return new VehicleResource($save->execute($this->owned(Vehicle::class, $vehicle), $this->validated($request, false), $request->user()));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'plate' => [$required, 'string', 'max:20'],
            'customerPublicId' => ['sometimes', 'nullable', 'uuid'],
            // The specification gives brand, model and type; the reception may register a vehicle with only its type.
            'vehicleSpecPublicId' => ['sometimes', 'nullable', 'uuid'],
            'vehicleTypePublicId' => [$creating ? 'required_without:vehicleSpecPublicId' : 'sometimes', 'nullable', 'uuid'],
            'color' => ['sometimes', 'nullable', 'string', 'max:40'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'isActive' => ['sometimes', 'boolean'],
        ]);
    }
}
