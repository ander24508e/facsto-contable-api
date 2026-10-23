<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('ruc', 13)->nullable()->change();
            $table->string('legal_name', 180)->nullable()->change();
            $table->string('main_address', 255)->nullable()->change();
        });

        Schema::create('company_fiscal_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('accounting_required')->default(false);
            $table->string('special_taxpayer_number', 20)->nullable();
            $table->string('withholding_agent_number', 20)->nullable();
            $table->string('rimpe_type', 32)->nullable();
            $table->string('sri_environment', 16)->nullable();
            $table->string('emission_type', 16)->nullable();
            $table->timestamps();
        });

        if (Schema::hasColumn('companies', 'accounting_required')) {
            DB::table('companies')->orderBy('id')->chunkById(100, function ($companies): void {
                foreach ($companies as $company) {
                    DB::table('company_fiscal_settings')->insert([
                        'company_id' => $company->id,
                        'accounting_required' => $company->accounting_required,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('accounting_required');
            });
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('accounting_required')->default(false);
        });

        DB::table('company_fiscal_settings')->orderBy('id')->chunkById(100, function ($settings): void {
            foreach ($settings as $setting) {
                DB::table('companies')->where('id', $setting->company_id)->update([
                    'accounting_required' => $setting->accounting_required,
                ]);
            }
        });

        Schema::dropIfExists('company_fiscal_settings');
    }
};
