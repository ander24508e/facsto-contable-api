<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_fiscal_settings', function (Blueprint $table): void {
            $table->timestamp('setup_deferred_at')->nullable()->after('emission_type');
        });
    }

    public function down(): void
    {
        Schema::table('company_fiscal_settings', function (Blueprint $table): void {
            $table->dropColumn('setup_deferred_at');
        });
    }
};
