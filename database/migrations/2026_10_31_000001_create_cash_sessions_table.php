<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cajas: cada sesión de una caja (punto de emisión) con su bodega: quién la abrió y la cerró, cuándo y con
 * cuánto dinero (monto fijo o conteo por billetes y monedas), y los ingresos y egresos de dinero entre medio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('emission_point_id');
            $table->foreignId('warehouse_id');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            // MANUAL: monto fijo escrito; COUNTED: suma de billetes y monedas (guardados en `denominations`).
            $table->string('opening_mode', 16);
            $table->decimal('opening_amount', 14, 2);
            $table->json('denominations')->nullable();
            $table->string('notes', 255)->nullable();
            $table->string('status', 16)->default('OPEN');
            // Cierre: lo esperado (apertura + ingresos − egresos), lo contado y la diferencia (+ sobrante, − faltante).
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('closing_mode', 16)->nullable();
            $table->decimal('closing_amount', 14, 2)->nullable();
            $table->json('closing_denominations')->nullable();
            $table->decimal('expected_amount', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->nullable();
            $table->string('closing_notes', 255)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status']);
            $table->foreign(['company_id', 'emission_point_id'])->references(['company_id', 'id'])->on('emission_points')->restrictOnDelete();
            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
        });

        // Una caja sólo puede tener una apertura sin cerrar.
        DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open_per_point ON cash_sessions (emission_point_id) WHERE status = 'OPEN'");

        // Ingresos (IN) y egresos (OUT) de dinero registrados mientras la caja está abierta.
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id');
            $table->string('type', 8);
            $table->decimal('amount', 14, 2);
            $table->string('concept', 120);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['cash_session_id', 'created_at']);
            $table->foreign(['company_id', 'cash_session_id'])->references(['company_id', 'id'])->on('cash_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
    }
};
