<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_ruc_format_check CHECK (ruc ~ '^[0-9]{13}$')");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_identification_format_check CHECK (
            (identification_type = 'RUC' AND identification ~ '^[0-9]{13}$') OR
            (identification_type = 'CEDULA' AND identification ~ '^[0-9]{10}$') OR
            (identification_type = 'FINAL_CONSUMER' AND identification = '9999999999999') OR
            (identification_type IN ('PASSPORT', 'FOREIGN_IDENTIFICATION') AND identification ~ '^[A-Z0-9-]{3,20}$')
        )");
        DB::statement("ALTER TABLE company_memberships ADD CONSTRAINT company_memberships_role_check CHECK (role IN ('OWNER', 'ADMIN', 'BILLER', 'VIEWER'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE company_memberships DROP CONSTRAINT IF EXISTS company_memberships_role_check');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_identification_format_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_ruc_format_check');
    }
};
