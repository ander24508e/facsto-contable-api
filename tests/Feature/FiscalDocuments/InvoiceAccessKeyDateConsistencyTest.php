<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class InvoiceAccessKeyDateConsistencyTest extends InvoiceFeatureTestCase
{
    public function test_issue_date_and_access_key_use_the_same_calendar_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 00:00:01', 'America/Guayaquil'));

        try {
            $user = User::factory()->create();
            $company = $this->readyCompany($user);
            $sale = $this->makeSale($user, $company);

            $document = $this->actingAs($user)
                ->postJson('/api/v1/sales/'.$sale->public_id.'/issue')
                ->assertCreated()
                ->json('data');

            $this->assertSame(
                Carbon::parse($document['issueDate'])->format('dmY'),
                substr($document['accessKey'], 0, 8),
            );
        } finally {
            Carbon::setTestNow();
        }
    }
}
