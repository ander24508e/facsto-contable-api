<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobante de retención (codDoc 07, esquema 2.0.0). sri_withholding_codes es el catálogo global de
 * códigos de retención de Renta (tabla del ATS) e IVA con su porcentaje sugerido; cada línea de una
 * retención congela código, base, porcentaje y valor, porque el SRI cambia los porcentajes con resoluciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sri_withholding_codes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // RENTA (codigo 1) o IVA (codigo 2).
            $table->string('tax', 8);
            $table->string('code', 5);
            $table->string('name', 255);
            $table->decimal('percentage', 5, 2);
            // Porcentaje variable (p. ej. «otros porcentajes»): se escribe al emitir.
            $table->boolean('percentage_editable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tax', 'code']);
        });

        Schema::create('electronic_document_withholdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('electronic_document_id');
            $table->char('tax_code', 1);
            $table->string('withholding_code', 5);
            $table->string('name', 255);
            $table->decimal('taxable_base', 18, 2);
            $table->decimal('percentage', 5, 2);
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->foreign(['company_id', 'electronic_document_id'])->references(['company_id', 'id'])->on('electronic_documents')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sri_withholding_codes ADD CONSTRAINT sri_withholding_codes_tax_check CHECK (tax IN ('RENTA','IVA'))");
            DB::statement('ALTER TABLE electronic_document_withholdings ADD CONSTRAINT electronic_document_withholdings_amounts_check CHECK (taxable_base >= 0 AND percentage >= 0 AND percentage <= 100 AND amount >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_document_withholdings');
        Schema::dropIfExists('sri_withholding_codes');
    }
};
