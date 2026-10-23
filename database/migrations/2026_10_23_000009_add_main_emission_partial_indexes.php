<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('establishments', 'is_main')) {
            Schema::table('establishments', fn (Blueprint $table) => $table->boolean('is_main')->default(false));
        }
        if (! Schema::hasColumn('emission_points', 'is_default')) {
            Schema::table('emission_points', fn (Blueprint $table) => $table->boolean('is_default')->default(false));
        }

        DB::statement('CREATE UNIQUE INDEX establishments_one_main_per_company ON establishments (company_id) WHERE is_main = true');
        DB::statement('CREATE UNIQUE INDEX emission_points_one_default_per_establishment ON emission_points (establishment_id) WHERE is_default = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS establishments_one_main_per_company');
        DB::statement('DROP INDEX IF EXISTS emission_points_one_default_per_establishment');
        Schema::table('emission_points', fn (Blueprint $table) => $table->dropColumn('is_default'));
        Schema::table('establishments', fn (Blueprint $table) => $table->dropColumn('is_main'));
    }
};
