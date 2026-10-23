<?php

namespace Tests\Feature\Sales;

use App\Domains\Catalog\Models\Tax;
use App\Models\User;

final class SaleTaxSnapshotTest extends SalesTestCase
{
    public function test_tax_definition_is_copied_into_sale_line(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $tax = Tax::query()->create(['tax_type' => 'IVA', 'sri_code' => '2', 'percentage_code' => '99', 'name' => 'IVA test', 'percentage' => '10.000000', 'valid_from' => today()->subDay(), 'is_active' => true]);
        $sale = $this->makeSale($user, $company, false);
        $line = $sale->details()->firstOrFail();
        $item = $line->item;
        $item->taxes()->attach($tax->id);
        $this->actingAs($user)->withHeaders($this->companyHeader($company))->patchJson('/api/v1/sales/'.$sale->public_id, [
            'customerPublicId' => $sale->customer->public_id, 'lines' => [['itemPublicId' => $item->public_id, 'quantity' => '1']], 'paymentMethods' => [],
        ])->assertOk();
        $this->assertSame('IVA test', $sale->details()->first()->taxes()->first()->tax_name);
        $tax->forceFill(['name' => 'Nombre posterior'])->save();
        $this->assertSame('IVA test', $sale->details()->first()->taxes()->first()->tax_name);
    }
}
