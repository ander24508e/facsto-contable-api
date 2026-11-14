<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proformas: ofertas a un tercero con su propia numeración (PRO-000001); no tocan caja, ventas ni el SRI. Guardan el
 * cálculo del momento (mismo motor que la venta). Stock: una proforma pendiente RESERVA sus productos sin descontarlos;
 * con anticipo o a crédito los DESCUENTA (stock_committed) hasta convertirse en venta o rechazarse.
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
            // Seguimiento: PENDING (reserva), ACCEPTED (reserva), REJECTED (libera, con motivo), CONVERTED (venta facturada).
            // «Vencida» no se guarda: es una pendiente o aceptada con la fecha de vencimiento pasada (libera la reserva).
            $table->string('status', 12)->default('PENDING');
            $table->string('rejection_reason', 120)->nullable();
            $table->timestamp('converted_at')->nullable();
            // Anticipo o crédito: descuenta el stock de sus productos (stock_committed) en vez de sólo reservarlo.
            $table->decimal('advance_amount', 18, 2)->default(0);
            $table->foreignId('advance_payment_method_id')->nullable()->constrained('fiscal_payment_methods')->restrictOnDelete();
            $table->boolean('on_credit')->default(false);
            $table->boolean('stock_committed')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
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

        // The kardex keeps the proforma that took or gave back units (PROFORMA / PROFORMA_RELEASE).
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreign(['company_id', 'proforma_id'])->references(['company_id', 'id'])->on('proformas')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE proforma_lines ADD CONSTRAINT proforma_lines_quantity_positive_check CHECK (quantity > 0)');
            DB::statement('ALTER TABLE proformas ADD CONSTRAINT proformas_expiration_date_check CHECK (expiration_date >= issue_date)');
            DB::statement("ALTER TABLE proformas ADD CONSTRAINT proformas_status_check CHECK (status IN ('PENDING', 'ACCEPTED', 'REJECTED', 'CONVERTED'))");
            DB::statement('ALTER TABLE proformas ADD CONSTRAINT proformas_advance_check CHECK (advance_amount >= 0 AND (advance_amount = 0 OR advance_payment_method_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropForeign(['company_id', 'proforma_id']));
        Schema::dropIfExists('proforma_lines');
        Schema::dropIfExists('proformas');
    }
};
