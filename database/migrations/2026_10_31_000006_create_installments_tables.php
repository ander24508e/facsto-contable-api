<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuotas de lo que se vende o se compra a crédito: cuentas por cobrar (sale_installments) y cuentas por
 * pagar (purchase_installments). Cada cuota tiene su fecha y su valor; paid_amount guarda lo abonado.
 * credit_payments: cada cobro (venta) o pago (compra) registrado, que se reparte en las cuotas de la más
 * antigua a la más nueva. Un cobro entra a la caja de la venta (cash_movement_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sale' => 'sales', 'purchase' => 'purchases'] as $owner => $table) {
            Schema::create("{$owner}_installments", function (Blueprint $blueprint) use ($owner, $table): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
                $blueprint->foreignId("{$owner}_id");
                $blueprint->unsignedSmallInteger('number');
                $blueprint->date('due_date');
                $blueprint->decimal('amount', 18, 2);
                $blueprint->decimal('paid_amount', 18, 2)->default(0);
                $blueprint->timestamps();
                $blueprint->unique(["{$owner}_id", 'number']);
                $blueprint->index(['company_id', 'due_date']);
                $blueprint->foreign(['company_id', "{$owner}_id"])->references(['company_id', 'id'])->on($table)->cascadeOnDelete();
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$owner}_installments ADD CONSTRAINT {$owner}_installments_amounts_check CHECK (amount > 0 AND paid_amount >= 0 AND paid_amount <= amount)");
            }
        }

        Schema::create('credit_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable();
            $table->foreignId('purchase_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('notes', 255)->nullable();
            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['company_id', 'purchase_id'])->references(['company_id', 'id'])->on('purchases')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE credit_payments ADD CONSTRAINT credit_payments_owner_check CHECK (amount > 0 AND ((sale_id IS NULL) <> (purchase_id IS NULL)))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_payments');
        Schema::dropIfExists('purchase_installments');
        Schema::dropIfExists('sale_installments');
    }
};
