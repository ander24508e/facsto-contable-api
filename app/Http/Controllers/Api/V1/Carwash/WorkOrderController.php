<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Actions\ChargeWorkOrderAction;
use App\Domains\Carwash\Actions\CheckoutWorkOrderAction;
use App\Domains\Carwash\Actions\CreateWorkOrderAction;
use App\Domains\Carwash\Actions\TransitionWorkOrderAction;
use App\Domains\Carwash\Actions\UpdateWorkOrderAction;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Services\WorkOrderPricing;
use App\Domains\Sales\Enums\SaleStatus;
use App\Http\Resources\Api\V1\Carwash\WorkOrderResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Work orders: reception, washing, charge in Ventas and delivery. */
final class WorkOrderController extends CarwashController
{
    public const RELATIONS = ['vehicle.type', 'customer', 'washer', 'workArea', 'creator', 'lines.service', 'lines.item.taxes.definition', 'sale.electronicDocument', 'sale.paymentMethods'];

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
        // «OT-000012», «LV12» or «12»: the number without its prefix (Configuración › Órdenes).
        $number = ltrim((string) preg_replace('/^[A-Z]{1,6}-?/', '', strtoupper($search)), '0');

        return WorkOrder::query()
            ->where('company_id', $this->companyId())
            ->when($this->ownWorkOnly(), fn ($query) => $query->where('washer_id', auth()->id()))
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

    /** The code the next order will take, shown on «Nueva orden»; the real one is assigned when it is saved. */
    public function nextCode(): JsonResponse
    {
        $settings = CarwashSetting::forCompany($this->companyId());
        $number = max((int) WorkOrder::query()->where('company_id', $this->companyId())->max('number') + 1, (int) $settings->order_start_number);

        return response()->json(['data' => ['code' => (new WorkOrder(['number' => $number, 'code_prefix' => $settings->order_prefix]))->code()]]);
    }

    public function show(string $workOrder): WorkOrderResource
    {
        $order = $this->owned(WorkOrder::class, $workOrder);
        abort_if($this->ownWorkOnly() && $order->washer_id !== auth()->id(), 404);

        return $this->present($order);
    }

    /** «Ver su propio trabajo» without «Ver»: only the orders assigned to the user. */
    private function ownWorkOnly(): bool
    {
        return ! Gate::allows('carwash.view');
    }

    public function store(Request $request, CreateWorkOrderAction $create): JsonResponse
    {
        $data = $request->validate([
            'vehiclePublicId' => ['required', 'uuid'],
            'servicePublicIds' => ['required', 'array', 'min:1', 'max:10'],
            'servicePublicIds.*' => ['uuid', 'distinct'],
            'washerPublicId' => ['nullable', 'uuid'],
            'scheduledAt' => ['nullable', 'date'],
            'workAreaPublicId' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'dirty' => ['sometimes', 'boolean'],
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
            'workAreaPublicId' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'dirty' => ['sometimes', 'boolean'],
        ]);

        return $this->present($update->execute($this->owned(WorkOrder::class, $workOrder), $data, $request->user()));
    }

    public function transition(Request $request, string $workOrder, TransitionWorkOrderAction $transition): WorkOrderResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(WorkOrderStatus::class)],
            'reason' => ['nullable', 'string', 'max:300'],
            // Configuración › Servicios «Checklist de recepción», answered when the vehicle is received.
            'checklist' => ['sometimes', 'nullable', 'array', 'max:30'],
            'checklist.*.label' => ['required', 'string', 'max:80'],
            'checklist.*.checked' => ['sometimes', 'boolean'],
            'checklist.*.note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        return $this->present($transition->execute($this->owned(WorkOrder::class, $workOrder), WorkOrderStatus::from($data['status']), $data['reason'] ?? null, $request->user(), $data['checklist'] ?? null));
    }

    /** The checkout window's totals: the order's lines with the Lavadora's discounts plus the products added. */
    public function quote(Request $request, string $workOrder, WorkOrderPricing $pricing): JsonResponse
    {
        $data = $request->validate([
            'extraLines' => ['sometimes', 'array', 'max:30'],
            'extraLines.*.itemPublicId' => ['required', 'uuid'],
            'extraLines.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ]);

        return response()->json(['data' => $pricing->quote($this->owned(WorkOrder::class, $workOrder), $data['extraLines'] ?? [])]);
    }

    /**
     * The checkout window: charges the order in the open cash register (consumidor final or another customer, products
     * added, payment), issues the invoice when «Factura» is on (else the sale stays as a draft in Ventas) and delivers it
     * when «Entregar» is chosen.
     */
    public function checkout(Request $request, string $workOrder, CheckoutWorkOrderAction $checkout): WorkOrderResource
    {
        $data = $request->validate([
            'customerPublicId' => ['required', 'uuid'],
            'cashSessionPublicId' => ['required', 'uuid'],
            'extraLines' => ['sometimes', 'array', 'max:30'],
            'extraLines.*.itemPublicId' => ['required', 'uuid'],
            'extraLines.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'paymentMethods' => ['required', 'array', 'min:1', 'max:10'],
            'paymentMethods.*.fiscalPaymentMethodPublicId' => ['required', 'uuid'],
            'paymentMethods.*.amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'paymentMethods.*.reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'invoice' => ['required', 'boolean'],
            'deliver' => ['required', 'boolean'],
        ]);

        return $this->present($checkout->execute($this->owned(WorkOrder::class, $workOrder), $data, $request->user()));
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
