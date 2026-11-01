<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de dinero de una sesión de caja: ingresos (IN) y egresos (OUT). `source` dice de dónde vienen:
 * MANUAL (escritos en la caja), SALE (el efectivo de una factura, con el abono de un CREDITO), REFUND (el
 * efectivo devuelto por una nota de crédito de devolución) o CREDIT (el cobro de cuotas de cuentas por cobrar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id');
            $table->string('type', 8);
            $table->string('source', 8)->default('MANUAL');
            $table->decimal('amount', 14, 2);
            $table->string('concept', 120);
            $table->foreignId('sale_id')->nullable();
            $table->foreignId('electronic_document_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['cash_session_id', 'created_at']);
            $table->foreign(['company_id', 'cash_session_id'])->references(['company_id', 'id'])->on('cash_sessions')->cascadeOnDelete();
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['company_id', 'electronic_document_id'])->references(['company_id', 'id'])->on('electronic_documents')->restrictOnDelete();
        });

        // Una venta entra a la caja una sola vez; una nota de crédito devuelve su efectivo una sola vez.
        DB::statement("CREATE UNIQUE INDEX cash_movements_one_per_sale ON cash_movements (sale_id) WHERE source = 'SALE'");
        DB::statement("CREATE UNIQUE INDEX cash_movements_one_per_refund ON cash_movements (electronic_document_id) WHERE source = 'REFUND'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_kind_check CHECK (type IN ('IN', 'OUT') AND source IN ('MANUAL', 'SALE', 'REFUND', 'CREDIT') AND amount > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
