<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fiscal_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('accounting_required')->default(false);
            $table->string('special_taxpayer_number', 20)->nullable();
            $table->string('withholding_agent_number', 20)->nullable();
            $table->string('rimpe_type', 32)->nullable();
            $table->string('sri_environment', 16)->nullable();
            $table->string('emission_type', 16)->nullable();
            // Separado del ambiente: TEST no implica transmitir. F06 exige este flag y SRI_TRANSPORT_ENABLED.
            $table->boolean('sri_submission_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_fiscal_settings');
    }
};
