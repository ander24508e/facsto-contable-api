<?php

namespace App\Domains\Sales\Services;

use App\Domains\Catalog\Models\Item;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Collection;

final class SaleDraftCalculator
{
    /** @param list<array{itemPublicId: string, quantity: string, discountAmount?: string|null}> $lines
     * @param  Collection<string, Item>  $items
     * @return array{details: list<array<string, mixed>>, subtotal: string, discountTotal: string, taxTotal: string, tipAmount: string, total: string}
     */
    public function calculate(array $lines, Collection $items, string $tipAmount = '0.00'): array
    {
        $details = [];
        $subtotal = '0.00';
        $discountTotal = '0.00';
        $taxTotal = '0.00';

        foreach ($lines as $line) {
            $item = $items->get($line['itemPublicId']);
            if (! $item) {
                throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'Uno de los artículos no está activo en la empresa actual.', 422);
            }

            $quantity = $line['quantity'];
            if (bccomp($quantity, '0', 6) <= 0) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'La cantidad debe ser mayor que cero.', 422);
            }

            foreach ($item->taxes as $tax) {
                if (! $tax->isUsableOn(today())) {
                    throw new ApiException(ApiErrorCode::TAX_NOT_AVAILABLE, 'El artículo tiene una tasa que ya no está vigente. Actualiza su configuración antes de continuar.', 422);
                }
            }

            $unitPrice = $this->unitPriceWithoutTaxes($item, $quantity);
            $gross = $this->round(bcmul($quantity, $unitPrice, 8));
            if (bccomp($gross, '9999999999999999.99', 2) > 0) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El subtotal de una línea supera el máximo permitido.', 422);
            }
            $discount = $this->round($line['discountAmount'] ?? '0');
            if (bccomp($discount, $gross, 2) > 0) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El descuento no puede superar el subtotal de la línea.', 422);
            }

            $base = bcsub($gross, $discount, 2);
            $lineTaxes = [];
            $lineTaxTotal = '0.00';

            foreach ($item->taxes as $tax) {
                // Code and percentage always come from the official definition, never from the company.
                $definition = $tax->definition;
                $amount = $this->round(bcdiv(bcmul($base, (string) $definition->fixed_percentage, 8), '100', 8));
                $lineTaxTotal = bcadd($lineTaxTotal, $amount, 2);
                $lineTaxes[] = [
                    'company_tax_id' => $tax->id,
                    'tax_type' => $definition->tax_type->value,
                    'sri_code' => $definition->sri_tax_code,
                    'percentage_code' => $definition->sri_percentage_code,
                    'tax_name' => $tax->name,
                    'percentage' => $definition->fixed_percentage,
                    'taxable_base' => $base,
                    'tax_amount' => $amount,
                ];
            }

            $lineTotal = bcadd($base, $lineTaxTotal, 2);
            $details[] = [
                'item' => $item,
                'quantity' => bcadd($quantity, '0', 6),
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'subtotal' => $gross,
                'taxable_base' => $base,
                'tax_total' => $lineTaxTotal,
                'total' => $lineTotal,
                'taxes' => $lineTaxes,
            ];

            $subtotal = bcadd($subtotal, $gross, 2);
            $discountTotal = bcadd($discountTotal, $discount, 2);
            $taxTotal = bcadd($taxTotal, $lineTaxTotal, 2);
        }

        // Tabla 21: VALOR TOTAL = subtotal + impuestos + propina.
        $tipAmount = $this->round($tipAmount);
        $total = bcadd(bcadd(bcsub($subtotal, $discountTotal, 2), $taxTotal, 2), $tipAmount, 2);
        if (bccomp($total, '9999999999999999.99', 2) > 0) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El total supera el máximo permitido para este borrador.', 422);
        }

        return compact('details', 'subtotal', 'discountTotal', 'taxTotal', 'tipAmount', 'total');
    }

    /**
     * The SRI receives prices without taxes. When the business types its final price (taxes included),
     * the unit base is that price divided by (1 + the item's tax rates), kept at the XML's 6 decimals.
     */
    private function unitPriceWithoutTaxes(Item $item, string $quantity): string
    {
        $price = $this->listPrice($item, $quantity);
        if (! $item->price_includes_tax) {
            return $price;
        }
        $rate = '0';
        foreach ($item->taxes as $tax) {
            $rate = bcadd($rate, (string) $tax->definition->fixed_percentage, 6);
        }
        if (bccomp($rate, '0', 6) === 0) {
            return $price;
        }

        return $this->round(bcdiv(bcmul($price, '100', 12), bcadd('100', $rate, 6), 12), 6);
    }

    /** The sale price, or the wholesale price of the largest tier the quantity reaches. */
    private function listPrice(Item $item, string $quantity): string
    {
        $price = (string) $item->sale_price;
        foreach ($item->priceTiers as $tier) {
            if (bccomp($quantity, (string) $tier->min_quantity, 6) >= 0) {
                $price = (string) $tier->price;
            }
        }

        return $price;
    }

    private function round(string $value, int $scale = 2): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $fraction = str_pad($fraction, $scale + 1, '0');
        $rounded = $integer.'.'.substr($fraction, 0, $scale);

        if ((int) $fraction[$scale] >= 5) {
            $increment = '0.'.str_repeat('0', max(0, $scale - 1)).'1';
            $rounded = bcadd($rounded, $increment, $scale);
        }

        return $negative ? '-'.$rounded : $rounded;
    }
}
