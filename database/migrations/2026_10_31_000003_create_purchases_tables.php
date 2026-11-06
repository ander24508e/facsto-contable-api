<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inversiones: las compras del negocio a sus proveedores. Un proveedor es su RUC (o cédula), razón social y
 * contacto. Cada compra suma sus productos a una bodega (movimiento PURCHASE del kardex), deja el último
 * costo en el artículo y, si es a crédito, se paga en cuotas (purchase_installments). Numeración propia
 * COM-000001; no es un comprobante del SRI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('identification', 13);
            $table->string('legal_name', 300);
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'identification']);
            $table->unique(['company_id', 'id']);
        });

        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('supplier_id');
            $table->foreignId('warehouse_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('issue_date');
            // Factura del proveedor (001-001-000000123), opcional.
            $table->string('supplier_document_number', 17)->nullable();
            // Autorización de esa factura en el SRI (opcional): sale en la retención como numAutDocSustento.
            $table->string('supplier_authorization_number', 49)->nullable();
            $table->foreignId('fiscal_payment_method_id')->nullable()->constrained('fiscal_payment_methods')->restrictOnDelete();
            $table->string('payment_method_code', 12)->nullable();
            $table->string('payment_method_name', 120)->nullable();
            // CREDITO: el abono se paga al comprar; el saldo, en cuotas (purchase_installments).
            $table->boolean('is_credit')->default(false);
            $table->decimal('down_payment', 18, 2)->default(0);
            $table->string('installment_frequency', 16)->nullable();
            $table->string('credit_notes', 500)->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'issue_date']);
            $table->foreign(['company_id', 'supplier_id'])->references(['company_id', 'id'])->on('suppliers')->restrictOnDelete();
            $table->foreign(['company_id', 'warehouse_id'])->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
        });

        Schema::create('purchase_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id');
            $table->foreignId('item_id');
            $table->string('item_code', 25);
            $table->string('item_name', 180);
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('tax_percentage', 8, 4)->default(0);
            // Código SRI de la tarifa de IVA (tabla 17): la retención declara los impuestos del sustento.
            $table->string('tax_percentage_code', 8)->default('0');
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax_total', 18, 2);
            $table->decimal('total', 18, 2);
            $table->timestamps();
            $table->foreign(['company_id', 'purchase_id'])->references(['company_id', 'id'])->on('purchases')->cascadeOnDelete();
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE purchase_lines ADD CONSTRAINT purchase_lines_amounts_check CHECK (quantity > 0 AND unit_cost >= 0 AND tax_percentage >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
