<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario: unidades de la empresa, una bodega por establecimiento, existencias por artículo y bodega,
 * movimientos (base del kardex) y precios alternos por cantidad (mayorista).
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
            // Un establecimiento tiene una sola bodega.
            $table->unique('establishment_id');
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
            $table->unique(['item_id', 'warehouse_id']);
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->cascadeOnDelete();
            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id');
            $table->foreignId('warehouse_id');
            $table->string('type', 24);
            // Cambio con signo (+ entra, - sale) y saldo resultante en esa bodega.
            $table->decimal('quantity', 18, 6);
            $table->decimal('balance', 18, 6);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['item_id', 'created_at']);
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
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('INITIAL', 'ADJUSTMENT'))");
            DB::statement('ALTER TABLE item_price_tiers ADD CONSTRAINT item_price_tiers_values_check CHECK (min_quantity > 1 AND price >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_price_tiers');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('item_stocks');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('units');
    }
};
