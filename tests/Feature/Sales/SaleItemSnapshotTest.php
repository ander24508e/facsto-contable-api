<?php

namespace Tests\Feature\Sales;

use App\Models\User;

final class SaleItemSnapshotTest extends SalesTestCase
{
    public function test_item_values_are_snapshotted_on_sale(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $sale = $this->makeSale($user, $company);
        $line = $sale->details()->firstOrFail();
        $line->item->forceFill(['name' => 'Nombre posterior', 'sale_price' => '99.000000'])->save();
        $this->assertSame('Servicio', $line->fresh()->item_name);
        $this->assertSame('10.00', $line->fresh()->total);
    }
}
