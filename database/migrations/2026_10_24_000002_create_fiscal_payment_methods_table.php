<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fiscal_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 12);
            $table->string('name', 120);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['code', 'valid_from']);
            $table->index(['is_active', 'valid_from', 'valid_until']);
        });

        Schema::table('sale_payment_methods', function (Blueprint $table): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE sale_payment_methods DROP CONSTRAINT IF EXISTS sale_payment_methods_method_check');
            }
            $table->renameColumn('method', 'method_code');
        });
        Schema::table('sale_payment_methods', function (Blueprint $table): void {
            $table->foreignId('fiscal_payment_method_id')->nullable()->after('sale_id')->constrained('fiscal_payment_methods')->restrictOnDelete();
            $table->string('method_name', 120)->nullable()->after('method_code');
        });
        DB::table('sale_payment_methods')->whereNull('method_name')->update(['method_name' => DB::raw('method_code')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_payment_methods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fiscal_payment_method_id');
            $table->dropColumn('method_name');
            $table->renameColumn('method_code', 'method');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sale_payment_methods ADD CONSTRAINT sale_payment_methods_method_check CHECK (method IN ('CASH', 'BANK_TRANSFER', 'CARD', 'CREDIT'))");
        }
        Schema::dropIfExists('fiscal_payment_methods');
    }
};
