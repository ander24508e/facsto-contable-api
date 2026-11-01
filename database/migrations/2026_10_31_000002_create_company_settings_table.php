<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistema: cómo se comporta FACSTO para toda la empresa. Cada valor trae un predeterminado sensato, así una
 * empresa nueva funciona sin entrar nunca a Sistema. El límite de consumidor final NO va aquí: es una regla
 * fiscal global del SRI (`fiscal_rules`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // Inventario: un producto con esta cantidad o menos está en «stock bajo».
            $table->unsignedInteger('low_stock_threshold')->default(5);
            // Ventas: facturar aunque la bodega no tenga las unidades (apagado: sin stock no se vende).
            $table->boolean('allow_negative_stock')->default(false);
            // Nueva venta: forma de pago inicial (código SRI) y cliente inicial (EMPTY o FINAL_CONSUMER).
            $table->string('default_payment_method', 2)->nullable();
            $table->string('default_customer', 16)->default('EMPTY');
            // Productos nuevos: IVA y unidad que vienen elegidos.
            $table->foreignId('default_company_tax_id')->nullable()->constrained('company_taxes')->nullOnDelete();
            $table->string('default_unit', 32)->nullable();
            // Seguridad: minutos sin uso tras los que se cierra la sesión (0 = nunca).
            $table->unsignedSmallInteger('idle_timeout_minutes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
