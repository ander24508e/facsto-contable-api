<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Líneas de una orden de la Lavadora: sus servicios, recargos y productos. Cada línea apunta al artículo de FACSTO que
 * se factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carwash_work_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id');
            // SERVICE: un servicio de Tarifas (con su precio por vehículo, o sin él con precio único); SURCHARGE: un recargo;
            // PRODUCT: un artículo de Inventario agregado al agendar, a su precio.
            $table->string('kind', 10)->default('SERVICE');
            $table->foreignId('service_id')->nullable();
            $table->foreignId('service_price_id')->nullable();
            $table->foreignId('item_id');
            $table->string('description', 300);
            $table->decimal('quantity', 12, 6)->default(1);
            $table->unsignedSmallInteger('duration_minutes');
            $table->timestamps();
            $table->index('work_order_id');
            $table->foreign(['company_id', 'work_order_id'])->references(['company_id', 'id'])->on('carwash_work_orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'service_id'])->references(['company_id', 'id'])->on('carwash_services')->restrictOnDelete();
            $table->foreign(['company_id', 'service_price_id'])->references(['company_id', 'id'])->on('carwash_service_prices')->restrictOnDelete();
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE carwash_work_order_lines ADD CONSTRAINT carwash_work_order_lines_kind_check CHECK (kind IN ('SERVICE','SURCHARGE','PRODUCT') AND (kind <> 'SERVICE' OR service_id IS NOT NULL))");
            DB::statement('ALTER TABLE carwash_work_order_lines ADD CONSTRAINT carwash_work_order_lines_quantity_check CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_work_order_lines');
    }
};
