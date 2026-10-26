<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reglas normativas globales y versionadas; las empresas sólo las consultan.
        Schema::create('fiscal_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64);
            $table->string('value_type', 16);
            $table->decimal('value_decimal', 18, 6)->nullable();
            $table->string('value_string', 255)->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('legal_basis', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['code', 'valid_from']);
            $table->index(['code', 'is_active', 'valid_from']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fiscal_rules ADD CONSTRAINT fiscal_rules_value_type_check CHECK (value_type IN ('DECIMAL','STRING'))");
            DB::statement('ALTER TABLE fiscal_rules ADD CONSTRAINT fiscal_rules_validity_check CHECK (valid_until IS NULL OR valid_until >= valid_from)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_rules');
    }
};
