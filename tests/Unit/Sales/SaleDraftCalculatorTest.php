<?php

namespace Tests\Unit\Sales;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\Tax;
use App\Domains\Sales\Services\SaleDraftCalculator;
use App\Shared\Http\ApiException;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SaleDraftCalculatorTest extends TestCase
{
    #[Test]
    public function it_calculates_decimal_quantities_discounts_multiple_tax_snapshots_and_rounding(): void
    {
        $item = new Item(['internal_code' => 'A', 'name' => 'Servicio', 'unit' => 'UND', 'sale_price' => '10.000000']);
        $item->setRelation('taxes', new Collection([
            new Tax(['tax_type' => 'IVA', 'sri_code' => '2', 'percentage_code' => 'A', 'name' => 'IVA 10', 'percentage' => '10.000000', 'valid_from' => today()->subDay(), 'is_active' => true]),
            new Tax(['tax_type' => 'FEE', 'sri_code' => '3', 'percentage_code' => 'B', 'name' => 'Tasa 2.5', 'percentage' => '2.500000', 'valid_from' => today()->subDay(), 'is_active' => true]),
        ]));
        $result = app(SaleDraftCalculator::class)->calculate([['itemPublicId' => 'a', 'quantity' => '1.250000', 'discountAmount' => '0.25']], new Collection(['a' => $item]));
        $this->assertSame('12.50', $result['subtotal']);
        $this->assertSame('0.25', $result['discountTotal']);
        $this->assertSame('1.54', $result['taxTotal']);
        $this->assertSame('13.79', $result['total']);
    }

    #[Test]
    public function it_rejects_a_discount_above_subtotal(): void
    {
        $item = new Item(['sale_price' => '10.000000']);
        $item->setRelation('taxes', new Collection);
        $this->expectException(ApiException::class);
        app(SaleDraftCalculator::class)->calculate([['itemPublicId' => 'a', 'quantity' => '1', 'discountAmount' => '10.01']], new Collection(['a' => $item]));
    }

    #[Test]
    public function it_rejects_an_expired_tax_definition(): void
    {
        $item = new Item(['sale_price' => '10.000000']);
        $tax = new Tax(['is_active' => true, 'percentage' => '15.000000', 'valid_from' => today()->subYear(), 'valid_until' => today()->subDay()]);
        $item->setRelation('taxes', new Collection([$tax]));
        $this->expectException(ApiException::class);
        app(SaleDraftCalculator::class)->calculate([['itemPublicId' => 'a', 'quantity' => '1']], new Collection(['a' => $item]));
    }

    #[Test]
    public function it_accepts_a_single_quantity_and_an_item_without_tax(): void
    {
        $item = new Item(['internal_code' => 'A', 'name' => 'Servicio', 'unit' => 'UND', 'sale_price' => '10.005000']);
        $item->setRelation('taxes', new Collection);
        $result = app(SaleDraftCalculator::class)->calculate([['itemPublicId' => 'a', 'quantity' => '1']], new Collection(['a' => $item]));
        $this->assertSame('10.01', $result['total']);
        $this->assertSame([], $result['details'][0]['taxes']);
    }

    #[Test]
    public function it_allows_a_discount_equal_to_the_line_subtotal(): void
    {
        $item = new Item(['sale_price' => '10.000000']);
        $item->setRelation('taxes', new Collection);
        $result = app(SaleDraftCalculator::class)->calculate([['itemPublicId' => 'a', 'quantity' => '1', 'discountAmount' => '10']], new Collection(['a' => $item]));
        $this->assertSame('0.00', $result['total']);
    }

    #[Test]
    public function it_rejects_an_item_that_is_not_in_the_company_scoped_collection(): void
    {
        $this->expectException(ApiException::class);
        app(SaleDraftCalculator::class)->calculate([['itemPublicId' => 'company-b-item', 'quantity' => '1']], new Collection);
    }
}
