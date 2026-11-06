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
        // Venta o compra a crédito: va al XML como 01 con el plazo en días hasta la última cuota.
        ['code' => '01', 'name' => 'CREDITO', 'valid_from' => '2013-01-01', 'is_credit' => true],
        // Venta por transferencia bancaria: va al XML como 20 y guarda la referencia. Sólo en ventas.
        ['code' => '20', 'name' => 'TRANSFERENCIA', 'valid_from' => '2016-06-01', 'is_transfer' => true],
    ];

    public function run(): void
    {
        foreach (self::METHODS as $method) {
            $isCredit = $method['is_credit'] ?? false;
            $isTransfer = $method['is_transfer'] ?? false;
            FiscalPaymentMethod::query()->where('code', $method['code'])->where('is_credit', $isCredit)->where('is_transfer', $isTransfer)
                ->whereDate('valid_from', $method['valid_from'])
                ->firstOrNew()
                ->fill([...$method, 'is_credit' => $isCredit, 'is_transfer' => $isTransfer, 'valid_until' => null, 'is_active' => true])
                ->save();
        }
    }
}
