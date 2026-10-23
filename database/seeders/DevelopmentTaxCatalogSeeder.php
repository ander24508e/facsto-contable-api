<?php

namespace Database\Seeders;

use App\Domains\Catalog\Models\Tax;
use Illuminate\Database\Seeder;

/**
 * Muestra local mínima para F02. El catálogo fiscal productivo debe mantenerse
 * con el catálogo oficial revisado antes de implementar emisión en F04/F05.
 */
final class DevelopmentTaxCatalogSeeder extends Seeder
{
    public function run(): void
    {
        Tax::query()->updateOrCreate(
            ['tax_type' => 'IVA', 'sri_code' => '2', 'percentage_code' => '4', 'valid_from' => '2024-04-01'],
            ['name' => 'IVA general 15%', 'percentage' => '15.000000', 'valid_until' => null, 'is_active' => true],
        );
    }
}
