<?php

namespace Tests\Feature\Companies;

use App\Models\User;

final class PrimaryStaysActiveTest extends CompanyTestCase
{
    public function test_the_main_establishment_cannot_be_turned_off_but_another_can(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $main = $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'is_main' => true]);
        $branch = $company->establishments()->create(['code' => '002', 'name' => 'Sucursal']);

        $this->actingAs($user)->patchJson('/api/v1/establishments/'.$main->public_id, ['isActive' => false])
            ->assertStatus(409)->assertJsonPath('error.code', 'PRIMARY_MUST_STAY_ACTIVE');
        $this->assertDatabaseHas('establishments', ['id' => $main->id, 'is_active' => true]);

        $this->actingAs($user)->patchJson('/api/v1/establishments/'.$branch->public_id, ['isActive' => false])->assertOk();
        $this->assertDatabaseHas('establishments', ['id' => $branch->id, 'is_active' => false]);

        // Making an inactive one the main turns it on, and the old main stops being it.
        $this->actingAs($user)->patchJson('/api/v1/establishments/'.$branch->public_id, ['isMain' => true])->assertOk();
        $this->assertDatabaseHas('establishments', ['id' => $branch->id, 'is_active' => true, 'is_main' => true]);
        $this->assertDatabaseHas('establishments', ['id' => $main->id, 'is_main' => false]);
    }

    public function test_the_main_emission_point_cannot_be_turned_off_but_another_can(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $establishment = $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'is_main' => true]);
        $default = $establishment->emissionPoints()->create(['company_id' => $company->id, 'code' => '001', 'name' => 'Caja 1', 'is_default' => true]);
        $other = $establishment->emissionPoints()->create(['company_id' => $company->id, 'code' => '002', 'name' => 'Caja 2']);

        $this->actingAs($user)->patchJson('/api/v1/emission-points/'.$default->public_id, ['isActive' => false])
            ->assertStatus(409)->assertJsonPath('error.code', 'PRIMARY_MUST_STAY_ACTIVE');
        $this->actingAs($user)->patchJson('/api/v1/emission-points/'.$other->public_id, ['isActive' => false])->assertOk();
        $this->assertDatabaseHas('emission_points', ['id' => $other->id, 'is_active' => false]);
    }
}
