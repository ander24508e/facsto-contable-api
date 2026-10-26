<?php

namespace Tests\Feature\Sales;

use App\Models\User;

final class SaleTaxSnapshotTest extends SalesTestCase
{
    public function test_tax_definition_is_copied_into_sale_line(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $tax = $this->customCompanyTax($company, ['sri_percentage_code' => '99', 'name' => 'IVA test', 'fixed_percentage' => '10.000000']);
        $sale = $this->makeSale($user, $company, false);
        $line = $sale->details()->firstOrFail();
        $item = $line->item;
        $item->taxes()->sync([$tax->id => ['company_id' => $company->id]]);
        $this->actingAs($user)->patchJson('/api/v1/sales/'.$sale->public_id, [
            'customerPublicId' => $sale->customer->public_id, 'lines' => [['itemPublicId' => $item->public_id, 'quantity' => '1']], 'paymentMethods' => [],
        ])->assertOk();
        $this->assertSame('IVA test', $sale->details()->first()->taxes()->first()->tax_name);
        $tax->forceFill(['name' => 'Nombre posterior'])->save();
        $tax->definition->forceFill(['fixed_percentage' => '20.000000'])->save();
        $this->assertSame('IVA test', $sale->details()->first()->taxes()->first()->tax_name);
        $this->assertSame('10.000000', $sale->details()->first()->taxes()->first()->percentage);
    }
}
