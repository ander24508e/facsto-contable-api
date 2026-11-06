<?php

namespace Tests\Feature\Purchases;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Companies\Models\Company;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Ride\Services\RideDataFactory;
use App\Domains\Sri\Xml\XsdValidator;
use App\Domains\Withholdings\Models\SriWithholdingCode;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

/** Retenciones (codDoc 07): issued on a purchase by a withholding agent, valid against the 2.0.0 schema. */
final class RetentionTest extends InvoiceFeatureTestCase
{
    private User $user;

    public function test_a_withholding_agent_retains_on_a_credit_purchase_and_lowers_what_it_owes(): void
    {
        $company = $this->agent();
        $purchase = $this->creditPurchase($company);
        $this->getJson('/api/v1/retentions/eligibility')->assertOk()->assertJsonPath('data.canWithhold', true)->assertJsonPath('data.reason', 'COMPANY');
        $this->getJson('/api/v1/withholding-codes?tax=IVA')->assertOk()->assertJsonPath('data.0.tax', 'IVA');

        // 312 bienes at 1.75 % (adjusted on issue) of 100.00 and 30 % of the 15.00 of IVA: 1.75 + 4.50.
        $data = $this->postJson("/api/v1/purchases/{$purchase}/retention", $this->body($company, [
            ['codePublicId' => $this->code('RENTA', '312'), 'taxableBase' => '100.00', 'percentage' => '1.75'],
            ['codePublicId' => $this->code('IVA', '1'), 'taxableBase' => '15.00'],
        ]))->assertCreated()
            ->assertJsonPath('data.fullNumber', '001-001-000000001')
            ->assertJsonPath('data.supportDocumentNumber', '001-001-000000123')
            ->assertJsonPath('data.withheldTotal', '6.25')
            ->assertJsonPath('data.withholdings.1.amount', '4.50')
            ->json('data');

        $retention = ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();
        $this->assertSame('07', substr($retention->access_key, 8, 2));
        $xml = Storage::disk('private')->get($retention->artifacts()->where('type', 'UNSIGNED_XML')->firstOrFail()->path);
        app(XsdValidator::class)->validate($xml, config('sri.retention.xsd_path'));
        $this->assertStringContainsString('<numDocSustento>001001000000123</numDocSustento>', $xml);
        $this->assertStringContainsString('<codigoRetencion>312</codigoRetencion><baseImponible>100.00</baseImponible><porcentajeRetener>1.75</porcentajeRetener><valorRetenido>1.75</valorRetenido>', $xml);
        $this->assertStringContainsString('<codigoPorcentaje>4</codigoPorcentaje><baseImponible>100.00</baseImponible><tarifa>15</tarifa><valorImpuesto>15.00</valorImpuesto>', $xml);

        // 100.00 on installments minus the 6.25 withheld.
        $this->getJson("/api/v1/payables/{$purchase}")->assertOk()->assertJsonPath('data.balance', '93.75');
        $this->getJson("/api/v1/purchases/{$purchase}")->assertOk()->assertJsonPath('data.retention.withheldTotal', '6.25');
        $this->postJson("/api/v1/purchases/{$purchase}/retention", $this->body($company, [['codePublicId' => $this->code('IVA', '1'), 'taxableBase' => '15.00']]))
            ->assertConflict()->assertJsonPath('error.code', 'RETENTION_ALREADY_EXISTS');
        $this->getJson('/api/v1/retentions')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.supplier.identification', '1790012345001');
        // A retention is not a document of Ventas.
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(0, 'data');

        // Once authorized, its RIDE lists the withholdings instead of products.
        $retention->forceFill(['status' => 'AUTHORIZED', 'authorization_number' => $retention->access_key, 'authorized_at' => now()])->save();
        $html = view('ride.invoice', ['ride' => app(RideDataFactory::class)->make($retention->refresh())])->render();
        $this->assertStringContainsString('TOTAL RETENIDO', $html);
    }

    public function test_bases_never_exceed_the_subtotal_or_the_iva_of_the_purchase(): void
    {
        $company = $this->agent();
        $purchase = $this->creditPurchase($company);

        $this->postJson("/api/v1/purchases/{$purchase}/retention", $this->body($company, [['codePublicId' => $this->code('IVA', '2'), 'taxableBase' => '15.01']]))
            ->assertUnprocessable()->assertJsonPath('error.details.limit', '15.00');
        $this->postJson("/api/v1/purchases/{$purchase}/retention", $this->body($company, [['codePublicId' => $this->code('RENTA', '312'), 'taxableBase' => '100.01']]))
            ->assertUnprocessable();
        $this->assertSame(0, ElectronicDocument::query()->where('document_type', 'RETENTION')->count());
    }

    public function test_a_rimpe_company_withholds_only_when_designated(): void
    {
        $company = $this->agent();
        $purchase = $this->creditPurchase($company);
        $company->fiscalSettings->forceFill(['rimpe_type' => 'ENTREPRENEUR'])->save();

        $this->getJson('/api/v1/retentions/eligibility')->assertOk()->assertJsonPath('data.canWithhold', false)->assertJsonPath('data.reason', 'RIMPE');
        $this->postJson("/api/v1/purchases/{$purchase}/retention", $this->body($company, [['codePublicId' => $this->code('IVA', '1'), 'taxableBase' => '15.00']]))
            ->assertConflict()->assertJsonPath('error.code', 'WITHHOLDING_AGENT_REQUIRED');

        $company->fiscalSettings->forceFill(['withholding_agent_number' => '1'])->save();
        $this->getJson('/api/v1/retentions/eligibility')->assertOk()->assertJsonPath('data.canWithhold', true)->assertJsonPath('data.reason', 'DESIGNATED_AGENT');
    }

    private function agent(): Company
    {
        $this->user = User::factory()->create();
        $company = $this->readyCompany($this->user);
        $this->actingAs($this->user);

        return $company;
    }

    /** 10 units at 10.00 plus IVA 15 %: 115.00, with 15.00 down and 100.00 in two installments. */
    private function creditPurchase(Company $company): string
    {
        $stock = $this->productStock($company, '1');
        $iva = $this->companyIva($company, '4')->public_id;
        $item = $this->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'CAJA', 'name' => 'Caja', 'unit' => 'UND', 'salePrice' => '20', 'stock' => $stock])
            ->assertCreated()->json('data.publicId');
        $supplier = $this->postJson('/api/v1/suppliers', ['identification' => '1790012345001', 'legalName' => 'Distribuidora Andina'])->assertCreated()->json('data.publicId');

        return $this->postJson('/api/v1/purchases', [
            'supplierPublicId' => $supplier, 'warehousePublicId' => $stock['warehousePublicId'],
            'fiscalPaymentMethodPublicId' => FiscalPaymentMethod::query()->where('is_credit', true)->value('public_id'),
            'supplierDocumentNumber' => '001-001-000000123', 'downPayment' => '15.00', 'installmentFrequency' => 'MONTHLY',
            'installments' => [['dueDate' => now()->addMonth()->toDateString(), 'amount' => '50.00'], ['dueDate' => now()->addMonths(2)->toDateString(), 'amount' => '50.00']],
            'lines' => [['itemPublicId' => $item, 'quantity' => '10', 'unitCost' => '10.00', 'taxPublicId' => $iva]],
        ])->assertCreated()->assertJsonPath('data.total', '115.00')->json('data.publicId');
    }

    private function body(Company $company, array $withholdings): array
    {
        return ['emissionPointPublicId' => $company->emissionPoints()->value('public_id'), 'supportCode' => '06', 'withholdings' => $withholdings];
    }

    private function code(string $tax, string $code): string
    {
        return SriWithholdingCode::query()->where('tax', $tax)->where('code', $code)->value('public_id');
    }
}
