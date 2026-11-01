<?php

namespace Tests\Feature\Purchases;

use App\Models\User;
use Tests\Feature\Sales\SalesTestCase;

/** Proveedores: created with a valid RUC or cédula, edited without changing it, activated and deactivated. */
final class SupplierTest extends SalesTestCase
{
    public function test_suppliers_are_managed_like_customers(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');
        $this->actingAs($user);

        $this->postJson('/api/v1/suppliers', ['identification' => '9999999999', 'legalName' => 'Mala'])->assertUnprocessable();
        $supplier = $this->postJson('/api/v1/suppliers', ['identification' => '1790012345001', 'legalName' => '  distribuidora   andina ', 'email' => 'Ventas@Andina.ec', 'address' => 'Quito'])
            ->assertCreated()->assertJsonPath('data.legalName', 'DISTRIBUIDORA ANDINA')->assertJsonPath('data.email', 'ventas@andina.ec')
            ->assertJsonPath('data.isActive', true)->json('data.publicId');

        $this->patchJson("/api/v1/suppliers/{$supplier}", ['identification' => '0102030405', 'legalName' => 'Andina'])->assertUnprocessable();
        $this->patchJson("/api/v1/suppliers/{$supplier}", ['legalName' => 'Andina Cía. Ltda.', 'phone' => '0999999999'])->assertOk()
            ->assertJsonPath('data.legalName', 'ANDINA CÍA. LTDA.')->assertJsonPath('data.identification', '1790012345001')
            ->assertJsonPath('data.phone', '0999999999')->assertJsonPath('data.address', null);

        $this->postJson("/api/v1/suppliers/{$supplier}/deactivate")->assertOk()->assertJsonPath('data.isActive', false);
        $this->getJson('/api/v1/suppliers?status=ACTIVE')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/suppliers?status=INACTIVE&search=andina')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson("/api/v1/suppliers/{$supplier}/activate")->assertOk()->assertJsonPath('data.isActive', true);
        $this->getJson("/api/v1/suppliers/{$supplier}")->assertOk()->assertJsonPath('data.purchasesCount', 0);

        $other = User::factory()->create();
        $this->createCompany($other, '1788888888001');
        $this->actingAs($other)->getJson("/api/v1/suppliers/{$supplier}")->assertNotFound();
        $this->patchJson("/api/v1/suppliers/{$supplier}", ['legalName' => 'X'])->assertNotFound();
    }
}
