<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categorías del catálogo activadas en la Lavadora (Configuración): sus servicios y productos aparecen en
 * Tarifas. Quitar una categoría sólo la oculta de Tarifas; los precios por vehículo siguen funcionando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carwash_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id');
            $table->timestamps();
            $table->unique(['company_id', 'category_id']);
            $table->foreign(['company_id', 'category_id'])->references(['company_id', 'id'])->on('categories')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_categories');
    }
};
