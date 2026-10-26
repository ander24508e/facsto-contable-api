<?php

namespace Database\Seeders;

use App\Domains\Taxes\Enums\FiscalRuleCode;
use App\Domains\Taxes\Enums\TaxCalculationType;
use App\Domains\Taxes\Enums\TaxType;
use App\Domains\Taxes\Models\FiscalRule;
use App\Domains\Taxes\Models\SriTaxDefinition;
use App\Domains\Taxes\Services\CompanyTaxCatalogSynchronizer;
use Illuminate\Database\Seeder;

/**
 * Catálogo normativo de FACSTO según la ficha técnica SRI offline v2.34. Corre en todos los ambientes,
 * es idempotente y es la forma en que Condore publica una ley nueva: se agrega una versión con su
 * valid_from y se vuelve a ejecutar; las empresas reciben las tarifas por defecto automáticamente.
 *
 * Tabla 17 sin precarga: el IVA diferenciado (código 8) se publica por decreto con sus días concretos
 * y el código 10 (13 %) se publicará con las fechas de la norma que lo active.
 */
final class SriCatalogSeeder extends Seeder
{
    private const TABLE_17 = 'Ficha técnica SRI offline v2.34, tabla 17';

    /** @var list<array{code: string, name: string, percentage: string, from: string, until: ?string}> */
    private const IVA_DEFINITIONS = [
        ['code' => '0', 'name' => 'IVA 0%', 'percentage' => '0.000000', 'from' => '2013-01-01', 'until' => null],
        ['code' => '4', 'name' => 'IVA 15%', 'percentage' => '15.000000', 'from' => '2024-04-01', 'until' => null],
        ['code' => '5', 'name' => 'IVA 5%', 'percentage' => '5.000000', 'from' => '2024-04-01', 'until' => null],
        ['code' => '6', 'name' => 'No objeto de IVA', 'percentage' => '0.000000', 'from' => '2013-01-01', 'until' => null],
        ['code' => '7', 'name' => 'Exento de IVA', 'percentage' => '0.000000', 'from' => '2013-01-01', 'until' => null],
        // Históricas: las notas de crédito usan la tarifa vigente a la fecha del documento de sustento.
        ['code' => '3', 'name' => 'IVA 14%', 'percentage' => '14.000000', 'from' => '2016-06-01', 'until' => '2017-05-31'],
        ['code' => '2', 'name' => 'IVA 12%', 'percentage' => '12.000000', 'from' => '2017-06-01', 'until' => '2024-03-31'],
    ];

    public function run(CompanyTaxCatalogSynchronizer $synchronizer): void
    {
        foreach (self::IVA_DEFINITIONS as $definition) {
            // whereDate: valid_from is cast to a date and may be stored with a time component.
            SriTaxDefinition::query()
                ->where(['tax_type' => TaxType::IVA->value, 'sri_percentage_code' => $definition['code']])
                ->whereDate('valid_from', $definition['from'])
                ->firstOrNew()
                ->fill([
                    'tax_type' => TaxType::IVA,
                    'sri_tax_code' => TaxType::IVA->sriCode(),
                    'sri_percentage_code' => $definition['code'],
                    'name' => $definition['name'],
                    'calculation_type' => TaxCalculationType::PERCENTAGE,
                    'fixed_percentage' => $definition['percentage'],
                    'percentage_editable' => false,
                    'calculation_supported' => true,
                    'enabled_by_default' => true,
                    'valid_from' => $definition['from'],
                    'valid_until' => $definition['until'],
                    'legal_basis' => self::TABLE_17,
                    'is_active' => true,
                ])
                ->save();
        }

        $this->rule(FiscalRuleCode::FINAL_CONSUMER_MAX_TOTAL, '50.00', '2023-02-01', 'Ficha técnica SRI offline v2.34, numeral 9.10 (monto actualizado en la versión 2.23 del 01/02/2023)');
        $this->rule(FiscalRuleCode::MAX_TIP_PERCENTAGE, '10.00', '2013-01-01', 'Ficha técnica SRI offline v2.34, numeral 9.16, tabla 21 (propina)');

        $this->call(SriFiscalPaymentMethodSeeder::class);
        $synchronizer->syncAllCompanies();
    }

    private function rule(FiscalRuleCode $code, string $value, string $validFrom, string $legalBasis): void
    {
        FiscalRule::query()->where('code', $code->value)->whereDate('valid_from', $validFrom)->firstOrNew()->fill([
            'code' => $code,
            'value_type' => 'DECIMAL',
            'value_decimal' => $value,
            'valid_from' => $validFrom,
            'valid_until' => null,
            'legal_basis' => $legalBasis,
            'is_active' => true,
        ])->save();
    }
}
