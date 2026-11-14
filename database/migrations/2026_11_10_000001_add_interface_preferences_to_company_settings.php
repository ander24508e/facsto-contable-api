<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->string('sales_interface', 16)->default('LIST');
            $table->string('proformas_interface', 16)->default('LIST');
            $table->string('investments_interface', 16)->default('LIST');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn(['sales_interface', 'proformas_interface', 'investments_interface']);
        });
    }
};
