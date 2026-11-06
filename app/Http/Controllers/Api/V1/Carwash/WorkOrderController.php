<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Actions\ChargeWorkOrderAction;
use App\Domains\Carwash\Actions\CreateWorkOrderAction;
use App\Domains\Carwash\Actions\TransitionWorkOrderAction;
use App\Domains\Carwash\Actions\UpdateWorkOrderAction;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Sales\Enums\SaleStatus;
use App\Http\Resources\Api\V1\Carwash\WorkOrderResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Work orders: reception, washing, charge in Ventas and delivery. */
final class WorkOrderController extends CarwashController
{
    public const RELATIONS = ['vehicle.type', 'customer', 'washer', 'lines.service', 'lines.item.taxes.definition', 'sale.electronicDocument'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(WorkOrderStatus::class)],
            'open' => ['sometimes', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'washerPublicId' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $base = $this->filtered($filters);
        $page = (clone $base)->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($request->boolean('open'), fn ($query) => $query->whereNotIn('status', [WorkOrderStatus::DELIVERED, WorkOrderStatus::CANCELLED]))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return WorkOrderResource::collection($page)->additional(['meta' => ['summary' => $this->summary($base)]]);
    }

    /**
     * The cards of Órdenes, with the same date, washer and search as the list (not its state filter):
     * all, pending (not started yet), in progress and charged (their invoice has a number).
     */
    private function summary(Builder $base): array
    {
        return [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->whereIn('status', [WorkOrderStatus::PENDING, WorkOrderStatus::ARRIVED])->count(),
            'inProgress' => (clone $base)->where('status', WorkOrderStatus::IN_PROGRESS)->count(),
            'charged' => (clone $base)->whereHas('sale', fn ($sale) => $sale->where('status', SaleStatus::DOCUMENT_PREPARED))->count(),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function filtered(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $number = ltrim(strtoupper(str_replace(['OT-', 'OT'], '', strtoupper($search))), '0');

        return WorkOrder::query()
            ->where('company_id', $this->companyId())
            ->when($filters['washerPublicId'] ?? null, fn ($query, $washer) => $query->whereHas('washer', fn ($owner) => $owner->where('public_id', $washer)))
            ->when($filters['date'] ?? null, function ($query, string $date): void {
                $day = CarbonImmutable::parse($date, config('app.timezone'));
                $query->where(fn ($inDay) => $inDay->whereBetween('scheduled_at', [$day->startOfDay(), $day->endOfDay()])
                    ->orWhere(fn ($walkIn) => $walkIn->whereNull('scheduled_at')->whereBetween('created_at', [$day->startOfDay(), $day->endOfDay()])));
            })
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->whereHas('vehicle', fn ($vehicle) => $vehicle->where('plate', 'like', '%'.strtoupper((string) preg_replace('/[\s\-.]/', '', $search)).'%'))
                ->orWhereHas('customer', fn ($customer) => $customer->whereRaw('lower(legal_name) like ?', ['%'.mb_strtolower($search).'%']))
                ->when(ctype_digit($number), fn ($byNumber) => $byNumber->orWhere('number', (int) $number))));
    }

    public function show(string $workOrder): WorkOrderResource
    {
        return $this->present($this->owned(WorkOrder::class, $workOrder));
    }

    public function store(Request $request, CreateWorkOrderAction $create): JsonResponse
    {
        $data = $request->validate([
            'vehiclePublicId' => ['required', 'uuid'],
            'servicePublicIds' => ['required', 'array', 'min:1', 'max:10'],
            'servicePublicIds.*' => ['uuid', 'distinct'],
            'washerPublicId' => ['nullable', 'uuid'],
            'scheduledAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->present($create->execute($data, $request->user()))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $workOrder, UpdateWorkOrderAction $update): WorkOrderResource
    {
        $data = $request->validate([
            'servicePublicIds' => ['sometimes', 'array', 'min:1', 'max:10'],
            'servicePublicIds.*' => ['uuid', 'distinct'],
            'washerPublicId' => ['sometimes', 'nullable', 'uuid'],
            'scheduledAt' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        return $this->present($update->execute($this->owned(WorkOrder::class, $workOrder), $data, $request->user()));
    }

    public function transition(Request $request, string $workOrder, TransitionWorkOrderAction $transition): WorkOrderResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(WorkOrderStatus::class)],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        return $this->present($transition->execute($this->owned(WorkOrder::class, $workOrder), WorkOrderStatus::from($data['status']), $data['reason'] ?? null, $request->user()));
    }

    /** «Cobrar»: creates (or refreshes) the sale draft in Ventas. */
    public function charge(Request $request, string $workOrder, ChargeWorkOrderAction $charge): WorkOrderResource
    {
        return $this->present($charge->execute($this->owned(WorkOrder::class, $workOrder), $request->user()));
    }

    private function present(WorkOrder $order): WorkOrderResource
    {
        return new WorkOrderResource($order->load(self::RELATIONS));
    }
}
