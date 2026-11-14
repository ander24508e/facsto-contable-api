<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario: unidades de la empresa, bodegas (varias por establecimiento), la existencia de cada artículo
 * en su única bodega y precios alternos por cantidad (mayorista). Los movimientos (kardex) van en su propia
 * migración, después de los comprobantes que los originan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id');
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // Un establecimiento puede tener varias bodegas; cada bodega pertenece a uno solo.
            $table->unique(['establishment_id', 'name']);
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'establishment_id'])->references(['company_id', 'id'])->on('establishments')->restrictOnDelete();
        });

        Schema::create('item_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id');
            $table->foreignId('warehouse_id');
            $table->decimal('quantity', 18, 6)->default(0);
            $table->timestamps();
            // Un producto pertenece a una sola bodega.
            // A product may have units in several warehouses (Traslado); its first row is its main warehouse.
            $table->unique(['item_id', 'warehouse_id']);
            $table->index('warehouse_id');
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->cascadeOnDelete();
            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
        });

        Schema::create('item_price_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id');
            $table->decimal('min_quantity', 18, 6);
            $table->decimal('price', 18, 6);
            $table->timestamps();
            $table->unique(['item_id', 'min_quantity']);
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE item_price_tiers ADD CONSTRAINT item_price_tiers_values_check CHECK (min_quantity > 1 AND price >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_price_tiers');
        Schema::dropIfExists('item_stocks');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('units');
    }
};
