<?php

namespace Tests\Feature\Sales;

use App\Models\User;

final class SaleIsolationTest extends SalesTestCase
{
    public function test_other_company_member_cannot_read_or_edit_sale(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $companyA = $this->createCompany($owner, '1799999999001');
        $companyB = $this->createCompany($other, '1799999998001');
        $sale = $this->makeSale($owner, $companyA);
        $headers = $this->companyHeader($companyB);
        $this->actingAs($other)->withHeaders($headers)->getJson('/api/v1/sales/'.$sale->public_id)->assertForbidden();
        $this->actingAs($other)->withHeaders($headers)->patchJson('/api/v1/sales/'.$sale->public_id, [
            'customerPublicId' => $sale->customer->public_id,
            'lines' => [['itemPublicId' => $sale->details()->first()->item->public_id, 'quantity' => '1']],
            'paymentMethods' => [],
        ])->assertForbidden();
    }
}
