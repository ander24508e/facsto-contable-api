<?php

namespace Tests\Unit\Sales;

use App\Domains\Catalog\Models\Item;
use App\Domains\Sales\Services\SaleDraftCalculator;
use App\Domains\Taxes\Models\CompanyTax;
use App\Domains\Taxes\Models\SriTaxDefinition;
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
            $this->tax('IVA', '2', 'A', '10.000000'),
            $this->tax('ICE', '3', 'B', '2.500000'),
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
        $tax = $this->tax('IVA', '2', '2', '12.000000', today()->subDay());
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
    public function it_adds_the_tip_to_the_total(): void
    {
        $item = new Item(['sale_price' => '100.000000']);
        $item->setRelation('taxes', new Collection([$this->tax('IVA', '2', '4', '15.000000')]));
        $result = app(SaleDraftCalculator::class)->calculate([['itemPublicId' => 'a', 'quantity' => '1']], new Collection(['a' => $item]), '10.00');
        $this->assertSame('10.00', $result['tipAmount']);
        $this->assertSame('125.00', $result['total']);
    }

    #[Test]
    public function it_rejects_an_item_that_is_not_in_the_company_scoped_collection(): void
    {
        $this->expectException(ApiException::class);
        app(SaleDraftCalculator::class)->calculate([['itemPublicId' => 'company-b-item', 'quantity' => '1']], new Collection);
    }

    private function tax(string $type, string $sriCode, string $percentageCode, string $percentage, $validUntil = null): CompanyTax
    {
        $definition = new SriTaxDefinition([
            'tax_type' => $type, 'sri_tax_code' => $sriCode, 'sri_percentage_code' => $percentageCode, 'name' => "{$type} {$percentage}",
            'calculation_type' => 'PERCENTAGE', 'fixed_percentage' => $percentage, 'calculation_supported' => true,
            'valid_from' => today()->subYear(), 'valid_until' => $validUntil, 'is_active' => true,
        ]);
        $tax = new CompanyTax(['name' => $definition->name, 'is_active' => true]);
        $tax->setRelation('definition', $definition);

        return $tax;
    }
}
