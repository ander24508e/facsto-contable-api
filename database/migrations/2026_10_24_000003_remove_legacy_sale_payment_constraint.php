<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sale_payment_methods DROP CONSTRAINT IF EXISTS sale_payment_methods_method_check');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sale_payment_methods ADD CONSTRAINT sale_payment_methods_method_check CHECK (method_code IN ('CASH', 'BANK_TRANSFER', 'CARD', 'CREDIT'))");
        }
    }
};
