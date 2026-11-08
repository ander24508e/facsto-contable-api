<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proformas: ofertas a un tercero. Tienen su propia numeración (PRO-000001) y no tocan nada más: ni stock,
 * ni caja, ni la numeración de ventas, ni el SRI. Guardan el cálculo del momento (mismo motor que la venta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proformas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('customer_id')->nullable();
            // Bodega cuyos productos se ofrecen (sólo referencia: no reserva nada).
            $table->foreignId('warehouse_id')->nullable();
            $table->unsignedBigInteger('converted_sale_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('customer_identification_type', 32)->nullable();
            $table->string('customer_identification', 20)->nullable();
            $table->string('customer_name', 180)->nullable();
            $table->string('customer_address', 255)->nullable();
            $table->string('customer_email', 254)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->date('issue_date');
            $table->date('expiration_date');
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'converted_sale_id']);
            $table->index(['company_id', 'created_at']);
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['company_id', 'converted_sale_id'])->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
        });

        Schema::create('proforma_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proforma_id');
            $table->foreignId('item_id');
            $table->string('item_code', 25);
            $table->string('item_name', 180);
            $table->string('unit', 32);
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 6);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2);
            $table->decimal('taxable_base', 18, 2);
            $table->decimal('tax_total', 18, 2);
            $table->decimal('total', 18, 2);
            // Impuestos de la línea tal como los calculó el motor (tipo, código, tarifa, base y valor).
            $table->json('taxes');
            $table->timestamps();
            $table->foreign(['company_id', 'proforma_id'])->references(['company_id', 'id'])->on('proformas')->cascadeOnDelete();
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE proforma_lines ADD CONSTRAINT proforma_lines_quantity_positive_check CHECK (quantity > 0)');
            DB::statement('ALTER TABLE proformas ADD CONSTRAINT proformas_expiration_date_check CHECK (expiration_date >= issue_date)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_lines');
        Schema::dropIfExists('proformas');
    }
};
