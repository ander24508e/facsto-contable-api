<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Combos de la Lavadora (Configuración › Tarifas): cuando una orden lleva todos los servicios de un combo activo, al
 * cobrar esos servicios llevan el descuento del combo en Ventas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carwash_combos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('discount_percent', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'id']);
        });

        Schema::create('carwash_combo_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('combo_id');
            $table->foreignId('service_id');
            $table->unique(['combo_id', 'service_id']);
            $table->foreign(['company_id', 'combo_id'])->references(['company_id', 'id'])->on('carwash_combos')->cascadeOnDelete();
            $table->foreign(['company_id', 'service_id'])->references(['company_id', 'id'])->on('carwash_services')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE carwash_combos ADD CONSTRAINT carwash_combos_discount_check CHECK (discount_percent > 0 AND discount_percent <= 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_combo_services');
        Schema::dropIfExists('carwash_combos');
    }
};
