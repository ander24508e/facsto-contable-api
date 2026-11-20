<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\Combo;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Models\WorkOrderLine;
use App\Domains\Catalog\Models\Item;
use App\Domains\Sales\Services\SaleDraftCalculator;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Collection;

/**
 * The sale lines of an order when it is charged, with the discounts of Configuración (all as line discounts in Ventas,
 * so the invoice shows them):
 * - › Tarifas «Combos»: when the order has every service of an active combo, those lines get its percentage (each line
 *   in one combo, the biggest discounts first).
 * - › Clientes «Fidelización»: every N-th delivered visit of the vehicle has its most expensive service free.
 * - › Tarifas «Redondeo»: the total goes down to the step (0.05, 0.10…), taken from the line with the biggest base.
 * «Cuenta separada»: lines of the order (services or products) marked «Aparte» are charged as another sale, at their
 * price, without the order's discounts (`separate`); at least one line stays in the order.
 * The amounts come from the same calculator Ventas uses.
 */
final class WorkOrderPricing
{
    public function __construct(private readonly SaleDraftCalculator $calculator) {}

    /**
     * @param  list<array{itemPublicId: string, quantity: string}>  $extras  products or services added when charging
     * @param  list<string>  $apart  items of the order's lines charged in the separate sale («Aparte»)
     * @return array{lines: list<array{itemPublicId: string, quantity: string, discountAmount: string}>, items: Collection<string, Item>, loyaltyFree: bool, combos: list<string>}
     */
    public function lines(WorkOrder $order, array $extras = [], array $apart = []): array
    {
        $order->loadMissing(['lines.item.taxes.definition', 'lines.item.priceTiers', 'lines.service']);
        $settings = CarwashSetting::forCompany($order->company_id);
        $orderLines = $order->lines->reject(fn (WorkOrderLine $line) => in_array($line->item->public_id, $apart, true))->values();
        if ($orderLines->isEmpty() && $extras === []) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Deja al menos una línea en la cuenta de la orden.', 422, ['errors' => ['separate' => ['No todo va aparte.']]]);
        }
        $items = $orderLines->pluck('item')->keyBy('public_id');
        $lines = $orderLines->map(fn (WorkOrderLine $line) => ['itemPublicId' => $line->item->public_id, 'quantity' => (string) $line->quantity, 'discountAmount' => '0.00'])->all();
        if ($extras !== []) {
            $added = Item::query()->where('company_id', $order->company_id)->where('is_active', true)
                ->whereIn('public_id', array_column($extras, 'itemPublicId'))->with(['taxes.definition', 'priceTiers'])->get()->keyBy('public_id');
            foreach ($extras as $extra) {
                if (! $added->has($extra['itemPublicId'])) {
                    throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'Uno de los artículos agregados no está activo en la empresa.', 422);
                }
                $lines[] = ['itemPublicId' => $extra['itemPublicId'], 'quantity' => (string) $extra['quantity'], 'discountAmount' => '0.00'];
            }
            $items = $items->merge($added);
        }
        $gross = $this->grossOf($lines, $items);

        // Combos
        $applied = [];
        $taken = [];
        $serviceIds = $orderLines->map(fn (WorkOrderLine $line) => $line->kind === WorkOrderLine::SERVICE ? $line->service_id : null)->all();
        $combos = Combo::query()->with('services')->where('company_id', $order->company_id)->where('is_active', true)->orderByDesc('discount_percent')->get();
        foreach ($combos as $combo) {
            $needed = $combo->services->pluck('id')->all();
            if ($needed === []) {
                continue;
            }
            $indexes = [];
            foreach ($needed as $serviceId) {
                $index = collect($serviceIds)->search(fn ($id, $key) => $id === $serviceId && ! isset($taken[$key]));
                if ($index === false) {
                    continue 2;
                }
                $indexes[] = $index;
            }
            foreach ($indexes as $index) {
                $taken[$index] = true;
                $lines[$index]['discountAmount'] = $this->round(bcdiv(bcmul($gross[$index], (string) $combo->discount_percent, 8), '100', 8));
            }
            $applied[] = $combo->name;
        }

        // Fidelización: this visit is the N-th delivered (or about to be) of the vehicle, whoever brings it.
        $loyaltyFree = false;
        if ($settings->loyalty_every) {
            $before = WorkOrder::query()->where('company_id', $order->company_id)->where('vehicle_id', $order->vehicle_id)
                ->whereKeyNot($order->id)->where('status', WorkOrderStatus::DELIVERED)->count();
            if (($before + 1) % $settings->loyalty_every === 0) {
                $best = collect($gross)->filter(fn ($amount, $index) => ($orderLines[$index]->kind ?? null) === WorkOrderLine::SERVICE)->sortDesc()->keys()->first();
                if ($best !== null) {
                    $lines[$best]['discountAmount'] = $gross[$best];
                    $loyaltyFree = true;
                }
            }
        }

        // Redondeo hacia abajo.
        if ($settings->rounding_step !== null && bccomp((string) $settings->rounding_step, '0', 2) > 0) {
            $lines = $this->roundDown($lines, $items, $gross, (string) $settings->rounding_step);
        }

        return ['lines' => $lines, 'items' => $items, 'loyaltyFree' => $loyaltyFree, 'combos' => $applied];
    }

    /**
     * The totals the checkout window shows: each line with its price and discount, and subtotal, discount, IVA and
     * total, from the same calculator Ventas uses.
     *
     * @param  list<array{itemPublicId: string, quantity: string}>  $extras
     * @param  list<string>  $apart
     * @return array<string, mixed>
     */
    public function quote(WorkOrder $order, array $extras = [], array $apart = []): array
    {
        $priced = $this->lines($order, $extras, $apart);

        return [...$this->present($priced['lines'], $priced['items']), 'loyaltyFree' => $priced['loyaltyFree'], 'combos' => $priced['combos']];
    }

    /**
     * «Cuenta separada»: the lines of the order (services or products) marked «Aparte», at their price, as another sale.
     *
     * @param  list<string>  $apart  items of the order's lines
     * @return array{lines: list<array{itemPublicId: string, quantity: string, discountAmount: string}>, items: Collection<string, Item>}
     */
    public function separate(WorkOrder $order, array $apart): array
    {
        $order->loadMissing(['lines.item.taxes.definition', 'lines.item.priceTiers']);
        $chosen = $order->lines->filter(fn (WorkOrderLine $line) => in_array($line->item->public_id, $apart, true))->values();
        if ($chosen->isEmpty() || $chosen->pluck('item.public_id')->unique()->count() !== count(array_unique($apart))) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Una de las líneas aparte no es de esta orden.', 422, ['errors' => ['separate.items' => ['Línea desconocida.']]]);
        }

        return [
            'lines' => $chosen->map(fn (WorkOrderLine $line) => ['itemPublicId' => $line->item->public_id, 'quantity' => (string) $line->quantity, 'discountAmount' => '0.00'])->all(),
            'items' => $chosen->pluck('item')->keyBy('public_id'),
        ];
    }

    /**
     * @param  list<string>  $apart
     * @return array<string, mixed>
     */
    public function quoteSeparate(WorkOrder $order, array $apart): array
    {
        $priced = $this->separate($order, $apart);

        return $this->present($priced['lines'], $priced['items']);
    }

    /**
     * @param  list<array{itemPublicId: string, quantity: string, discountAmount: string}>  $lines
     * @return array<string, mixed>
     */
    private function present(array $lines, Collection $items): array
    {
        $result = $this->calculator->calculate($lines, $items);

        return [
            'lines' => array_map(fn (array $detail, array $line): array => [
                'itemPublicId' => $line['itemPublicId'],
                'name' => $detail['item']->name,
                'code' => $detail['item']->internal_code,
                'type' => $detail['item']->type->value,
                'quantity' => $detail['quantity'],
                'unitPrice' => $detail['unit_price'],
                'discount' => $detail['discount_amount'],
                'total' => $detail['total'],
            ], $result['details'], $lines),
            'subtotal' => $result['subtotal'],
            'discount' => $result['discountTotal'],
            'tax' => $result['taxTotal'],
            'total' => $result['total'],
        ];
    }

    /**
     * Lowers the total to a multiple of the step with an extra discount on the line with the biggest base. The tax is
     * computed per line, so the discount is found by trying the closest cents.
     *
     * @param  list<array{itemPublicId: string, quantity: string, discountAmount: string}>  $lines
     * @param  list<string>  $gross
     * @return list<array{itemPublicId: string, quantity: string, discountAmount: string}>
     */
    private function roundDown(array $lines, Collection $items, array $gross, string $step): array
    {
        $total = $this->calculator->calculate($lines, $items)['total'];
        $target = bcmul((string) floor((float) bcdiv($total, $step, 8) + 1e-9), $step, 2);
        $excess = bcsub($total, $target, 2);
        if (bccomp($excess, '0', 2) <= 0) {
            return $lines;
        }
        $bases = collect($lines)->map(fn ($line, $index) => bcsub($gross[$index], $line['discountAmount'], 2));
        $index = $bases->sortDesc()->keys()->first();
        $calculated = $this->calculator->calculate($lines, $items)['details'][$index];
        $rate = bccomp($calculated['taxable_base'], '0', 2) > 0 ? bcdiv($calculated['tax_total'], $calculated['taxable_base'], 8) : '0';
        $guess = $this->round(bcdiv($excess, bcadd('1', $rate, 8), 8));
        foreach ([0, 1, -1, 2, -2, 3] as $cents) {
            $extra = bcadd($guess, bcdiv((string) $cents, '100', 2), 2);
            $discount = bcadd($lines[$index]['discountAmount'], $extra, 2);
            if (bccomp($extra, '0', 2) <= 0 || bccomp($discount, $gross[$index], 2) > 0) {
                continue;
            }
            $trial = $lines;
            $trial[$index]['discountAmount'] = $discount;
            if (bccomp($this->calculator->calculate($trial, $items)['total'], $target, 2) === 0) {
                return $trial;
            }
        }

        // No exact cent reaches the step (taxes round per line): the nearest total below it.
        $lines[$index]['discountAmount'] = min(bcadd($lines[$index]['discountAmount'], $guess, 2), $gross[$index]);

        return $lines;
    }

    /**
     * @param  list<array{itemPublicId: string, quantity: string, discountAmount: string}>  $lines
     * @return list<string>
     */
    private function grossOf(array $lines, Collection $items): array
    {
        return array_map(fn (array $detail): string => (string) $detail['subtotal'], $this->calculator->calculate($lines, $items)['details']);
    }

    private function round(string $value): string
    {
        return bcadd($value, bccomp($value, '0', 8) >= 0 ? '0.005' : '-0.005', 2);
    }
}
