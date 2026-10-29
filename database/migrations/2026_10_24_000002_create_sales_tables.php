<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // Internal consecutive per company (V-00001); unrelated to the SRI sequential.
            $table->unsignedInteger('number');
            $table->string('status', 32)->default('DRAFT');
            // Fiscal date of the sale; fixed when the electronic document is prepared.
            $table->date('issue_date')->nullable();
            $table->string('customer_identification_type', 32)->nullable();
            $table->string('customer_identification', 20)->nullable();
            $table->string('customer_name', 180)->nullable();
            $table->string('customer_address', 255)->nullable();
            $table->string('customer_email', 254)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('tip_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            // Venta recibida de un módulo por API: quién la envió, su referencia y la clave de idempotencia.
            $table->foreignId('api_client_id')->nullable();
            $table->string('source_module', 40)->nullable();
            $table->string('source_reference', 120)->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'api_client_id', 'idempotency_key'], 'sales_idempotency_unique');
            $table->foreign(['company_id', 'api_client_id'])->references(['company_id', 'id'])->on('api_clients')->restrictOnDelete();
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['company_id', 'customer_id', 'created_at']);
        });

        Schema::create('sale_details', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id');
            $table->foreignId('item_id');
            $table->string('item_code', 25);
            $table->string('auxiliary_code', 25)->nullable();
            $table->string('item_name', 180);
            $table->string('unit', 32);
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 6);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2);
            $table->decimal('taxable_base', 18, 2);
            $table->decimal('tax_total', 18, 2);
            $table->decimal('total', 18, 2);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sales')->cascadeOnDelete();
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->restrictOnDelete();
        });

        Schema::create('sale_detail_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_detail_id');
            $table->foreignId('company_tax_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tax_type', 16);
            $table->string('sri_code', 12);
            $table->string('percentage_code', 12);
            $table->string('tax_name', 120);
            $table->decimal('percentage', 8, 6);
            $table->decimal('taxable_base', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->timestamps();
            $table->foreign(['company_id', 'sale_detail_id'])->references(['company_id', 'id'])->on('sale_details')->cascadeOnDelete();
        });

        Schema::create('sale_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id');
            $table->foreignId('fiscal_payment_method_id')->nullable()->constrained('fiscal_payment_methods')->restrictOnDelete();
            $table->string('method_code', 24);
            $table->string('method_name', 120)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('reference', 120)->nullable();
            $table->decimal('term', 12, 2)->nullable();
            $table->string('time_unit', 16)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sales')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_status_check CHECK (status IN ('DRAFT', 'IN_CORRECTION', 'DOCUMENT_PREPARED', 'CANCELLED'))");
            DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_totals_nonnegative_check CHECK (subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND tip_amount >= 0 AND total >= 0)');
            DB::statement('ALTER TABLE sale_details ADD CONSTRAINT sale_details_quantity_positive_check CHECK (quantity > 0)');
            DB::statement('ALTER TABLE sale_details ADD CONSTRAINT sale_details_amounts_nonnegative_check CHECK (unit_price >= 0 AND discount_amount >= 0 AND subtotal >= 0 AND taxable_base >= 0 AND tax_total >= 0 AND total >= 0)');
            DB::statement('ALTER TABLE sale_detail_taxes ADD CONSTRAINT sale_detail_taxes_amounts_nonnegative_check CHECK (percentage >= 0 AND taxable_base >= 0 AND tax_amount >= 0)');
            DB::statement('ALTER TABLE sale_payment_methods ADD CONSTRAINT sale_payment_methods_amount_nonnegative_check CHECK (amount >= 0 AND (term IS NULL OR term >= 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payment_methods');
        Schema::dropIfExists('sale_detail_taxes');
        Schema::dropIfExists('sale_details');
        Schema::dropIfExists('sales');
    }
};
