<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SriCatalogSeeder::class);
        // After the catalog: a new company copies the official rates.
        $this->call(InstanceAdministratorSeeder::class);

        if (App::environment('local')) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
