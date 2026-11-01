<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Domains\Companies\Enums\CompanyRole;
use App\Models\User;
use Tests\Feature\Sales\SalesTestCase;

final class CompanySettingsTest extends SalesTestCase
{
    public function test_a_new_company_works_with_the_defaults(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->getJson('/api/v1/company/settings')->assertOk()
            ->assertJsonPath('data.lowStockThreshold', 5)
            ->assertJsonPath('data.defaultCustomer', 'EMPTY')
            ->assertJsonPath('data.defaultPaymentMethod', null)
            ->assertJsonPath('data.idleTimeoutMinutes', 0);
    }

    public function test_the_owner_changes_the_settings_and_they_are_validated(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');

        $this->actingAs($user)->patchJson('/api/v1/company/settings', [
            'lowStockThreshold' => 10, 'defaultCustomer' => 'FINAL_CONSUMER', 'defaultUnit' => 'UND', 'idleTimeoutMinutes' => 30,
        ])->assertOk()
            ->assertJsonPath('data.lowStockThreshold', 10)
            ->assertJsonPath('data.defaultCustomer', 'FINAL_CONSUMER')
            ->assertJsonPath('data.defaultUnit', 'UND')
            ->assertJsonPath('data.idleTimeoutMinutes', 30);

        $this->patchJson('/api/v1/company/settings', ['defaultUnit' => 'NO-EXISTE'])->assertUnprocessable();
        $this->patchJson('/api/v1/company/settings', ['idleTimeoutMinutes' => 7])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.settings.updated']);
    }

    public function test_a_biller_reads_the_settings_but_cannot_change_them(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $biller = User::factory()->create();
        app(AddCompanyMemberAction::class)->execute($company, $owner, $biller, CompanyRole::BILLER);

        $this->actingAs($biller)->getJson('/api/v1/company/settings')->assertOk();
        $this->actingAs($biller)->patchJson('/api/v1/company/settings', ['lowStockThreshold' => 1])->assertForbidden();
    }

    public function test_the_low_stock_threshold_is_the_company_one(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $this->actingAs($user)->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'OCHO', 'name' => 'Ocho', 'unit' => 'UND', 'salePrice' => '1', 'stock' => $this->productStock($company, '8')])->assertCreated();

        // With the default (5) eight units are fine; with 10 they are low stock.
        $this->getJson('/api/v1/items?stock=low')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/company/settings', ['lowStockThreshold' => 10])->assertOk();
        $this->getJson('/api/v1/items?stock=low')->assertOk()->assertJsonCount(1, 'data');
        $action = collect($this->getJson('/api/v1/dashboard/actions')->assertOk()->json('data'))->firstWhere('code', 'LOW_STOCK');
        $this->assertSame(10, $action['threshold']);
    }
}
