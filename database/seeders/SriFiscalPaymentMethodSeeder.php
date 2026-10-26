<?php

namespace Database\Seeders;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use Illuminate\Database\Seeder;

final class SriFiscalPaymentMethodSeeder extends Seeder
{
    private const METHODS = [
        ['code' => '01', 'name' => 'SIN UTILIZACION DEL SISTEMA FINANCIERO', 'valid_from' => '2013-01-01'],
        ['code' => '15', 'name' => 'COMPENSACIÓN DE DEUDAS', 'valid_from' => '2013-01-01'],
        ['code' => '16', 'name' => 'TARJETA DE DÉBITO', 'valid_from' => '2016-06-01'],
        ['code' => '17', 'name' => 'DINERO ELECTRÓNICO', 'valid_from' => '2016-06-01'],
        ['code' => '18', 'name' => 'TARJETA PREPAGO', 'valid_from' => '2016-06-01'],
        ['code' => '19', 'name' => 'TARJETA DE CRÉDITO', 'valid_from' => '2016-06-01'],
        ['code' => '20', 'name' => 'OTROS CON UTILIZACIÓN DEL SISTEMA FINANCIERO', 'valid_from' => '2016-06-01'],
        ['code' => '21', 'name' => 'ENDOSO DE TÍTULOS', 'valid_from' => '2016-06-01'],
    ];

    public function run(): void
    {
        foreach (self::METHODS as $method) {
            FiscalPaymentMethod::query()->where('code', $method['code'])
                ->whereDate('valid_from', $method['valid_from'])
                ->firstOrNew()
                ->fill([...$method, 'valid_until' => null, 'is_active' => true])
                ->save();
        }
    }
}
