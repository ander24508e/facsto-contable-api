<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->change();
            $table->string('customer_identification_type', 32)->nullable()->change();
            $table->string('customer_identification', 20)->nullable()->change();
            $table->string('customer_name', 180)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('sales')->whereNull('customer_id')->exists()) {
            throw new RuntimeException('No se pueden restringir borradores con cliente pendiente.');
        }
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable(false)->change();
            $table->string('customer_identification_type', 32)->nullable(false)->change();
            $table->string('customer_identification', 20)->nullable(false)->change();
            $table->string('customer_name', 180)->nullable(false)->change();
        });
    }
};
