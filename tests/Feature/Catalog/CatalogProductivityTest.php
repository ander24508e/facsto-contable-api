<?php

namespace Tests\Feature\Catalog;

use App\Domains\Catalog\Models\Item;
use App\Domains\Customers\Models\Customer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Sales\SalesTestCase;

/** Category default rates, frequent items for the quick sale, and CSV imports with a preview. */
final class CatalogProductivityTest extends SalesTestCase
{
    public function test_the_item_list_shows_the_final_price_and_filters_by_category(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $category = $this->actingAs($user)->postJson('/api/v1/categories', ['name' => 'Bebidas'])->assertCreated()->json('data');
        $item = $this->postJson('/api/v1/items', [
            'type' => 'PRODUCT', 'internalCode' => 'AGUA', 'name' => 'Agua', 'unit' => 'UND', 'salePrice' => '10.000000', 'categoryPublicId' => $category['publicId'], 'stock' => $this->productStock($company),
        ])->assertCreated()->json('data');
        $this->putJson("/api/v1/items/{$item['publicId']}/taxes", ['taxPublicIds' => [$this->companyIva($company, '4')->public_id]])->assertOk();
        $this->postJson('/api/v1/items', ['type' => 'SERVICE', 'internalCode' => 'OTRO', 'name' => 'Otro', 'unit' => 'UND', 'salePrice' => '1'])->assertCreated();

        $this->getJson('/api/v1/items?category='.$category['publicId'])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.finalPrice', '11.50');
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.itemsCount', 1);
    }

    public function test_a_new_item_inherits_its_category_rates_and_a_replacement_can_target_one_category(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $iva15 = $this->companyIva($company, '4');
        $iva0 = $this->companyIva($company, '0');
        $this->actingAs($user);
        $category = $this->postJson('/api/v1/categories', ['name' => 'Repuestos'])->assertCreated()->json('data.publicId');
        $other = $this->postJson('/api/v1/categories', ['name' => 'Lubricantes'])->assertCreated()->json('data.publicId');
        $this->putJson("/api/v1/categories/{$category}/taxes", ['taxPublicIds' => [$iva15->public_id]])->assertOk()
            ->assertJsonPath('data.taxes.0.publicId', $iva15->public_id);
        $this->putJson("/api/v1/categories/{$other}/taxes", ['taxPublicIds' => [$iva15->public_id]])->assertOk();

        $item = $this->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'R-1', 'name' => 'Filtro', 'unit' => 'UND', 'salePrice' => '5', 'categoryPublicId' => $category, 'stock' => $this->productStock($company)])
            ->assertCreated()->assertJsonPath('data.taxes.0.publicId', $iva15->public_id)->json('data.publicId');
        $lubricant = $this->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'L-1', 'name' => 'Aceite', 'unit' => 'UND', 'salePrice' => '5', 'categoryPublicId' => $other, 'stock' => $this->productStock($company)])
            ->assertCreated()->json('data.publicId');

        $this->postJson('/api/v1/taxes/replacements', ['fromPublicId' => $iva15->public_id, 'toPublicId' => $iva0->public_id, 'categoryPublicId' => $category])
            ->assertOk()->assertJsonPath('data.itemCount', 1);
        $this->getJson("/api/v1/items/{$item}")->assertJsonPath('data.taxes.0.publicId', $iva0->public_id);
        $this->getJson("/api/v1/items/{$lubricant}")->assertJsonPath('data.taxes.0.publicId', $iva15->public_id);
        $this->getJson("/api/v1/categories/{$category}")->assertJsonPath('data.taxes.0.publicId', $iva0->public_id);
    }

    public function test_frequent_items_come_first(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        Item::query()->create(['company_id' => $company->id, 'internal_code' => 'A-1', 'type' => 'PRODUCT', 'name' => 'Aaa', 'unit' => 'UND', 'sale_price' => '1', 'is_active' => true]);
        $this->makeSale($user, $company);

        $this->actingAs($user)->getJson('/api/v1/items/frequent?limit=2')->assertOk()
            ->assertJsonPath('data.0.internalCode', 'SERV-1')
            ->assertJsonCount(2, 'data');
    }

    public function test_customers_are_imported_after_a_preview_that_marks_each_invalid_row(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');
        $csv = "Tipo identificacion;Identificacion;Razon social;Correo\n"
            ."CEDULA;1710034065;Ana Pérez;ana@example.com\n"
            .";1720127180001;Anderson Endara;\n"
            ."CEDULA;1710034066;Mal;\n";

        $preview = $this->actingAs($user)->post('/api/v1/imports/preview', ['type' => 'customers', 'file' => UploadedFile::fake()->createWithContent('clientes.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.valid', 2)->assertJsonPath('data.invalid', 1)
            ->assertJsonPath('data.rows.1.data.tipo_identificacion', 'RUC')
            ->assertJsonPath('data.rows.2.row', 4)->json('data');

        $this->postJson("/api/v1/imports/{$preview['id']}/commit", ['type' => 'customers'])->assertOk()
            ->assertJsonPath('data.created', 2)->assertJsonPath('data.skipped', 1);
        $this->assertSame(2, Customer::query()->count());
        $this->postJson("/api/v1/imports/{$preview['id']}/commit", ['type' => 'customers'])->assertStatus(410);
    }

    public function test_items_are_imported_with_their_iva_rate_and_category(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $this->companyIva($company, '4');
        $this->productStock($company);
        $csv = "tipo,codigo,nombre,precio,precio_incluye_iva,iva,categoria,stock,costo\n"
            ."PRODUCTO,F-1,Filtro,11.50,si,15,Repuestos,20,7.00\n"
            ."SERVICIO,S-1,Lavado,10,no,12,,,\n"
            ."PRODUCTO,F-2,Sin stock,5,no,15,,,\n";

        $preview = $this->actingAs($user)->post('/api/v1/imports/preview', ['type' => 'items', 'file' => UploadedFile::fake()->createWithContent('items.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.valid', 1)->json('data');
        $this->postJson("/api/v1/imports/{$preview['id']}/commit", ['type' => 'items'])->assertOk()->assertJsonPath('data.created', 1);

        $item = Item::query()->where('internal_code', 'F-1')->with(['taxes.definition', 'category'])->firstOrFail();
        $this->assertTrue($item->price_includes_tax);
        $this->assertSame('Repuestos', $item->category->name);
        $this->assertSame('4', $item->taxes->first()->definition->sri_percentage_code);
        $this->assertSame('20.000000', $item->stocks()->value('quantity'));
        $this->assertSame('7.000000', $item->cost_price);
    }
}
