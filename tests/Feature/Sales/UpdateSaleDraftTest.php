<?php

namespace Tests\Feature\Sales;

use App\Models\User;

final class UpdateSaleDraftTest extends SalesTestCase
{
    public function test_draft_can_be_recalculated_and_updated(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $sale = $this->makeSale($user, $company, false);
        $this->actingAs($user)->withHeaders($this->companyHeader($company))->patchJson('/api/v1/sales/'.$sale->public_id, [
            'customerPublicId' => $sale->customer->public_id, 'notes' => 'Updated', 'lines' => [['itemPublicId' => $sale->details->first()->item->public_id, 'quantity' => '2']], 'paymentMethods' => [],
        ])->assertOk()->assertJsonPath('data.total', '20.00')->assertJsonPath('data.notes', 'Updated');
    }
}
