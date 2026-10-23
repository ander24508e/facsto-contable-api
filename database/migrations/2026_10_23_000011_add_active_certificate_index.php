<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX digital_certificates_one_active_per_company ON digital_certificates (company_id) WHERE is_active = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS digital_certificates_one_active_per_company');
    }
};
