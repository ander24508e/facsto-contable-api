<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formas de pago: la tabla 24 del SRI más CREDITO, fija para todas las empresas. CREDITO envía al XML el
 * código 01 con el plazo en días y se cobra en cuotas (cuentas por cobrar o por pagar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 12);
            $table->string('name', 120);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            // CREDITO: abre «Configurar cuotas»; solo su abono entra a la caja.
            $table->boolean('is_credit')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['code', 'valid_from', 'is_credit']);
            $table->index(['is_active', 'valid_from', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_payment_methods');
    }
};
