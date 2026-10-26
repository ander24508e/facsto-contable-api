<?php

namespace Tests\Feature\FiscalDocuments;

use App\Models\User;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

final class InvoiceIssuerSnapshotTest extends InvoiceFeatureTestCase
{
    public function test_numbered_invoice_keeps_issuer_and_provider_values_after_company_changes(): void
    {
        config([
            'sri.software_provider.ruc' => '1791947096001',
            'sri.software_provider.name' => 'Condore Software',
        ]);

        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $company->forceFill(['commercial_name' => 'FACSTO Test'])->save();
        $settings = $company->fiscalSettings;
        $establishment = $company->establishments()->firstOrFail();
        $sale = $this->makeSale($user, $company);

        $this->actingAs($user)
            ->postJson('/api/v1/sales/'.$sale->public_id.'/invoice')
            ->assertCreated();

        $company->forceFill([
            'legal_name' => 'Razón social nueva',
            'commercial_name' => 'Nombre nuevo',
            'main_address' => 'Dirección nueva',
        ])->save();
        $establishment->forceFill(['address' => 'Otra dirección'])->save();
        $settings->forceFill(['accounting_required' => true, 'rimpe_type' => 'ENTREPRENEUR'])->save();

        $this->assertDatabaseHas('electronic_documents', [
            'sale_id' => $sale->id,
            'issuer_ruc' => '1790016919001',
            'issuer_legal_name' => 'Emisor válido',
            'issuer_commercial_name' => 'FACSTO Test',
            'issuer_main_address' => 'Quito',
            'establishment_address' => 'Quito',
            'accounting_required' => false,
            'sri_environment' => 'TEST',
            'emission_type' => 'NORMAL',
            'xml_version' => '1.1.0',
            'provider_ruc' => '1791947096001',
            'provider_name' => 'Condore Software',
        ]);
    }
}
