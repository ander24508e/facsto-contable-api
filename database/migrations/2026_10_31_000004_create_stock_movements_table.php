<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kardex: cada cambio de existencias de un artículo en su bodega, con signo (+ entra, − sale) y el saldo
 * que dejó. INITIAL y ADJUSTMENT los escribe Inventario; SALE la factura y RETURN la nota de crédito por
 * devolución, ambos con el comprobante que los originó; PURCHASE la compra de Inversiones; CONSUMPTION el
 * consumo interno (insumos que se gastan sin venderse), con el módulo y la referencia que lo originaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id');
            $table->foreignId('warehouse_id');
            $table->string('type', 24);
            $table->decimal('quantity', 18, 6);
            $table->decimal('balance', 18, 6);
            $table->foreignId('electronic_document_id')->nullable();
            $table->foreignId('purchase_id')->nullable();
            // Ajuste masivo (AJ-000001) y proforma con anticipo o a crédito: sus llaves se crean con sus tablas.
            $table->unsignedBigInteger('stock_adjustment_id')->nullable();
            $table->unsignedBigInteger('proforma_id')->nullable();
            // Motivo de un ingreso o egreso a mano (conteo, daño, pérdida, donación, consumo interno, otro).
            $table->string('reason', 24)->nullable();
            $table->string('source_module', 32)->nullable();
            $table->string('source_reference', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['item_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->cascadeOnDelete();
            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['company_id', 'electronic_document_id'])->references(['company_id', 'id'])->on('electronic_documents')->restrictOnDelete();
            $table->foreign(['company_id', 'purchase_id'])->references(['company_id', 'id'])->on('purchases')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('INITIAL', 'ADJUSTMENT', 'SALE', 'RETURN', 'PURCHASE', 'CONSUMPTION', 'ENTRY', 'EXIT', 'PROFORMA', 'PROFORMA_RELEASE', 'TRANSFER'))");
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_check CHECK (reason IS NULL OR reason IN ('COUNT', 'DAMAGE', 'LOSS', 'DONATION', 'INTERNAL_USE', 'OTHER'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
