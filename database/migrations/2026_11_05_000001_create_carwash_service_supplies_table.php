<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Insumos de un precio por vehículo: productos de Inventario (los mismos que se venden) y cuánto se gasta en
 * cada lavado. La cantidad va en la medida indicada (ml, L, g, kg), que se convierte con el contenido por unidad
 * del producto, o en su propia unidad si no hay medida. Se descuentan del kardex al terminar el lavado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carwash_service_supplies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_price_id');
            $table->foreignId('item_id');
            $table->decimal('quantity', 18, 6);
            $table->string('measure', 4)->nullable();
            $table->timestamps();
            $table->unique(['service_price_id', 'item_id']);
            $table->foreign(['company_id', 'service_price_id'])->references(['company_id', 'id'])->on('carwash_service_prices')->cascadeOnDelete();
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_service_supplies');
    }
};
