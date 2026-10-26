<?php

namespace Tests\Feature\Sales;

use App\Models\User;

final class CancelSaleDraftTest extends SalesTestCase
{
    public function test_draft_can_be_cancelled_without_hard_deletion(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $sale = $this->makeSale($user, $company, false);
        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/cancel')->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'CANCELLED']);
    }
}
