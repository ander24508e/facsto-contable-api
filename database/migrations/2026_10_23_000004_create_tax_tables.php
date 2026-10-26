<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo normativo global (ficha técnica SRI, tablas 16-18). Sólo FACSTO/Condore lo publica.
        Schema::create('sri_tax_definitions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('tax_type', 16);
            $table->string('sri_tax_code', 4);
            $table->string('sri_percentage_code', 8);
            $table->string('name', 120);
            $table->string('calculation_type', 16);
            $table->decimal('fixed_percentage', 8, 6)->nullable();
            $table->decimal('specific_rate', 18, 6)->nullable();
            $table->string('specific_unit', 40)->nullable();
            $table->boolean('percentage_editable')->default(false);
            // Sólo las definiciones cuyo cálculo FACSTO implementa pueden usarse en ventas.
            $table->boolean('calculation_supported')->default(false);
            // Se habilita automáticamente en cada empresa; las condicionadas (p. ej. IVA diferenciado) las activa la empresa.
            $table->boolean('enabled_by_default')->default(false);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('legal_basis', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tax_type', 'sri_percentage_code', 'valid_from'], 'sri_tax_definitions_version_unique');
            $table->index(['is_active', 'valid_from', 'valid_until']);
        });

        // Tarifas oficiales que cada empresa aplica a sus productos. No puede crear códigos ni porcentajes.
        Schema::create('company_taxes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sri_tax_definition_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            // Ventana opcional de la empresa dentro de la vigencia oficial (p. ej. días de un decreto).
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'sri_tax_definition_id']);
            $table->unique(['company_id', 'id'], 'company_taxes_company_id_id_unique');
            $table->index(['company_id', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sri_tax_definitions ADD CONSTRAINT sri_tax_definitions_type_check CHECK (tax_type IN ('IVA','ICE','IRBPNR'))");
            DB::statement("ALTER TABLE sri_tax_definitions ADD CONSTRAINT sri_tax_definitions_calculation_check CHECK (calculation_type IN ('PERCENTAGE','SPECIFIC','MIXED'))");
            DB::statement('ALTER TABLE sri_tax_definitions ADD CONSTRAINT sri_tax_definitions_validity_check CHECK (valid_until IS NULL OR valid_until >= valid_from)');
            DB::statement('ALTER TABLE sri_tax_definitions ADD CONSTRAINT sri_tax_definitions_percentage_range_check CHECK (fixed_percentage IS NULL OR (fixed_percentage >= 0 AND fixed_percentage <= 300))');
            DB::statement('ALTER TABLE company_taxes ADD CONSTRAINT company_taxes_validity_check CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_taxes');
        Schema::dropIfExists('sri_tax_definitions');
    }
};
