<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajuste masivo de stock (AJ-000001 por empresa): un conteo físico de varios productos guardado de una vez. Cada
 * producto que cambia deja su movimiento ADJUSTMENT enlazado a este ajuste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('note', 255)->nullable();
            $table->unsignedInteger('lines_count')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'id']);
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreign(['company_id', 'stock_adjustment_id'])->references(['company_id', 'id'])->on('stock_adjustments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropForeign(['company_id', 'stock_adjustment_id']));
        Schema::dropIfExists('stock_adjustments');
    }
};
