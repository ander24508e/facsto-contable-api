<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashCategory;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\ServicePrice;
use App\Domains\Carwash\Models\ServiceSupply;
use App\Domains\Carwash\Models\VehicleType;
use App\Domains\Carwash\Models\WorkArea;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Models\WorkOrderLine;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Services\CompanySettings;
use App\Domains\Inventory\Models\ItemStock;
use App\Domains\Sales\Services\SaleDraftCalculator;
use Carbon\CarbonImmutable;

/**
 * Lo que muestra Configuración además de sus ajustes:
 * - › Trabajadores «Comisiones»: por trabajador, las órdenes entregadas en el rango, la base sin IVA de sus servicios
 *   (la de su venta, con sus descuentos) y la comisión (la del servicio, o la general).
 * - › Insumos: los productos que gastan los servicios, con su stock y si está bajo (umbral de Sistema).
 * - «Pendiente por configurar»: lo que falta para operar.
 */
final class CarwashReports
{
    public function __construct(
        private readonly SaleDraftCalculator $calculator,
        private readonly WorkerDirectory $workers,
        private readonly CompanySettings $companySettings,
    ) {}

    /** @return array{from: string, to: string, washers: list<array<string, mixed>>, total: string} */
    public function commissions(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $settings = CarwashSetting::for($company);
        $orders = WorkOrder::query()->with(['washer', 'lines.service', 'lines.item.taxes.definition', 'lines.item.priceTiers', 'sale.details'])
            ->where('company_id', $company->id)->where('status', WorkOrderStatus::DELIVERED)->whereNotNull('washer_id')
            ->whereBetween('delivered_at', [$from->startOfDay(), $to->endOfDay()])->get();

        $rows = [];
        foreach ($orders as $order) {
            $key = $order->washer_id;
            $rows[$key] ??= ['washer' => ['publicId' => $order->washer->public_id, 'name' => $order->washer->name], 'orders' => 0, 'base' => '0.00', 'commission' => '0.00'];
            $rows[$key]['orders']++;
            foreach ($order->lines->where('kind', WorkOrderLine::SERVICE) as $line) {
                $base = $order->sale
                    ? (string) $order->sale->details->where('item_id', $line->item_id)->sum('taxable_base')
                    : $this->calculator->calculate([['itemPublicId' => $line->item->public_id, 'quantity' => (string) $line->quantity, 'discountAmount' => '0']], collect([$line->item->public_id => $line->item]))['subtotal'];
                $type = $line->service?->commission_type ?? $settings->commission_type;
                $value = (string) ($line->service?->commission_value ?? $settings->commission_value);
                $commission = $type === 'FIXED' ? bcmul($value, (string) $line->quantity, 2) : bcdiv(bcmul($base, $value, 6), '100', 2);
                $rows[$key]['base'] = bcadd($rows[$key]['base'], $base, 2);
                $rows[$key]['commission'] = bcadd($rows[$key]['commission'], $commission, 2);
            }
        }
        $washers = collect($rows)->sortBy(fn (array $row) => $row['washer']['name'])->values()->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'washers' => $washers,
            'total' => collect($washers)->reduce(fn (string $sum, array $row) => bcadd($sum, $row['commission'], 2), '0.00'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function supplies(Company $company): array
    {
        $threshold = (string) $this->companySettings->lowStockThreshold($company);
        $supplies = ServiceSupply::query()->with(['item'])
            ->where('company_id', $company->id)->get()->groupBy('item_id');
        $stock = ItemStock::query()->whereIn('item_id', $supplies->keys())->groupBy('item_id')->selectRaw('item_id, SUM(quantity) as total')->pluck('total', 'item_id');

        return $supplies->map(function ($uses, $itemId) use ($stock, $threshold): array {
            $item = $uses->first()->item;
            $quantity = bcadd((string) ($stock[$itemId] ?? '0'), '0', 6);

            return [
                'publicId' => $item->public_id,
                'name' => $item->name,
                'code' => $item->internal_code,
                'unit' => $item->unit,
                'stock' => $quantity,
                'low' => bccomp($quantity, $threshold, 6) <= 0,
                'usedBy' => $uses->count(),
            ];
        })->sortBy('name')->values()->all();
    }

    /** @return list<array{key: string, label: string, done: bool, tab: string}> */
    public function pending(Company $company): array
    {
        $settings = CarwashSetting::for($company);
        $rated = $settings->pricing_mode === CarwashSetting::PRICING_SINGLE
            ? CarwashCategory::query()->where('company_id', $company->id)->exists()
            : ServicePrice::query()->where('company_id', $company->id)->where('is_active', true)->exists();

        return [
            ['key' => 'hours', 'label' => 'Horario de atención', 'done' => $settings->setup_completed_at !== null || $settings->updated_at?->gt($settings->created_at), 'tab' => 'agenda'],
            ['key' => 'areas', 'label' => 'Áreas de trabajo', 'done' => WorkArea::query()->where('company_id', $company->id)->where('is_active', true)->exists(), 'tab' => 'areas'],
            ['key' => 'vehicles', 'label' => 'Tipos de vehículo', 'done' => VehicleType::query()->where('company_id', $company->id)->where('is_active', true)->exists(), 'tab' => 'vehicles'],
            ['key' => 'rates', 'label' => 'Tarifas con precio', 'done' => $rated, 'tab' => 'rates'],
            ['key' => 'workers', 'label' => 'Trabajadores', 'done' => $this->workers->list($company)->isNotEmpty(), 'tab' => 'workers'],
        ];
    }
}
