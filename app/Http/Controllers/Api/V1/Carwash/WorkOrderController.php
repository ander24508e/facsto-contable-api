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
            'billing' => ['nullable', 'in:UNBILLED,TO_INVOICE,INVOICED'],
            // «Mi trabajo»: only the orders assigned to the user.
            'mine' => ['sometimes', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $base = $this->filtered($filters);
        $page = (clone $base)->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($request->boolean('open'), fn ($query) => $query->whereNotIn('status', [WorkOrderStatus::DELIVERED, WorkOrderStatus::CANCELLED]))
            // Filtros › Facturación: without a sale, charged as a draft in Ventas («Por facturar») or with its invoice.
            ->when($filters['billing'] ?? null, fn ($query, string $billing) => match ($billing) {
                'UNBILLED' => $query->where(fn ($none) => $none->whereNull('sale_id')->orWhereHas('sale', fn ($sale) => $sale->where('status', SaleStatus::CANCELLED))),
                'TO_INVOICE' => $query->whereHas('sale', fn ($sale) => $sale->where('status', SaleStatus::DRAFT)),
                default => $query->whereHas('sale', fn ($sale) => $sale->whereIn('status', [SaleStatus::DOCUMENT_PREPARED, SaleStatus::IN_CORRECTION])),
            })
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
            ->when($this->ownWorkOnly() || ! empty($filters['mine']), fn ($query) => $query->where('washer_id', auth()->id()))
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
            // The vehicle by its id, or by its plate (found or registered with its specification or type).
            'vehiclePublicId' => ['required_without:plate', 'nullable', 'uuid'],
            'plate' => ['required_without:vehiclePublicId', 'nullable', 'string', 'max:20'],
            'vehicleSpecPublicId' => ['sometimes', 'nullable', 'uuid'],
            'vehicleTypePublicId' => ['sometimes', 'nullable', 'uuid'],
            // Whoever brings it this time; without it, the last customer of the vehicle.
            'customerPublicId' => ['sometimes', 'nullable', 'uuid'],
            'servicePublicIds' => ['required', 'array', 'min:1', 'max:10'],
            'servicePublicIds.*' => ['uuid', 'distinct'],
            // Articles of Inventario (products or services) added to the order, at their price.
            ...$this->lineRules('items'),
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
        $order = $this->owned(WorkOrder::class, $workOrder);
        $status = WorkOrderStatus::from($data['status']);
        // Without «Registrar y atender», only the wash steps of one's own orders (receive, wash, ready); delivering and
        // cancelling stay with the counter.
        if (! Gate::allows('carwash.operate')) {
            abort_if($order->washer_id !== auth()->id(), 404);
            abort_unless(in_array($status, [WorkOrderStatus::ARRIVED, WorkOrderStatus::IN_PROGRESS, WorkOrderStatus::READY], true), 403, 'Sólo el mostrador entrega o cancela la orden.');
        }

        return $this->present($transition->execute($order, $status, $data['reason'] ?? null, $request->user(), $data['checklist'] ?? null));
    }

    /** The checkout window's totals: the order's lines with the Lavadora's discounts plus the products added. */
    public function quote(Request $request, string $workOrder, WorkOrderPricing $pricing): JsonResponse
    {
        $data = $request->validate([...$this->lineRules('extraLines'), ...$this->lineRules('separateLines')]);
        $order = $this->owned(WorkOrder::class, $workOrder);

        return response()->json(['data' => [
            ...$pricing->quote($order, $data['extraLines'] ?? [], array_column($data['separateLines'] ?? [], 'itemPublicId')),
            'separate' => empty($data['separateLines']) ? null : $pricing->quoteSeparate($order->company_id, $data['separateLines']),
        ]]);
    }

    /**
     * The checkout window: charges the order in the open cash register (consumidor final or another customer, products
     * added, payment), issues the invoice when «Factura» is on (else the sale stays as a draft in Ventas) and delivers it
     * when «Entregar» is chosen. Payments may be mixed (several methods) or on credit (with its installments, as in
     * Ventas); `separate` charges some products as another sale («Cuenta aparte»).
     */
    public function checkout(Request $request, string $workOrder, CheckoutWorkOrderAction $checkout): WorkOrderResource
    {
        $data = $request->validate([
            'customerPublicId' => ['required', 'uuid'],
            'cashSessionPublicId' => ['required', 'uuid'],
            ...$this->lineRules('extraLines'),
            ...$this->paymentRules('paymentMethods'),
            'notes' => ['nullable', 'string', 'max:1000'],
            'invoice' => ['required', 'boolean'],
            'deliver' => ['required', 'boolean'],
            'separate' => ['sometimes', 'nullable', 'array'],
            'separate.customerPublicId' => ['required_with:separate', 'uuid'],
            'separate.invoice' => ['required_with:separate', 'boolean'],
            ...$this->lineRules('separate.lines', 'required_with:separate'),
            ...$this->paymentRules('separate.paymentMethods', 'required_with:separate'),
        ]);

        return $this->present($checkout->execute($this->owned(WorkOrder::class, $workOrder), $data, $request->user()));
    }

    /** @return array<string, list<string>> products added when charging (two decimals, more than zero) */
    private function lineRules(string $field, string $presence = 'sometimes'): array
    {
        return [
            $field => [$presence, 'array', $presence === 'sometimes' ? 'min:0' : 'min:1', 'max:30'],
            "$field.*.itemPublicId" => ['required', 'uuid'],
            "$field.*.quantity" => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ];
    }

    /** @return array<string, list<mixed>> one or several payments; CREDITO brings its down payment and installments as in Ventas */
    private function paymentRules(string $field, string $presence = 'required'): array
    {
        return [
            $field => [$presence, 'array', 'min:1', 'max:10'],
            "$field.*.fiscalPaymentMethodPublicId" => ['required', 'uuid'],
            "$field.*.amount" => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            "$field.*.reference" => ['nullable', 'string', 'max:120'],
            "$field.*.installments" => ['sometimes', 'nullable', 'array', 'max:120'],
            "$field.*.installments.*.dueDate" => ['required', 'date_format:Y-m-d'],
            "$field.*.installments.*.amount" => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            "$field.*.downPayment" => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            "$field.*.installmentFrequency" => ['sometimes', 'nullable', 'in:DAILY,BIWEEKLY,MONTHLY,SEMIANNUAL,ANNUAL'],
            "$field.*.creditNotes" => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
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
