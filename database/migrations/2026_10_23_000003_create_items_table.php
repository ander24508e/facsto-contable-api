<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable();
            $table->string('type', 16);
            $table->string('internal_code', 25);
            $table->string('auxiliary_code', 25)->nullable();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('unit', 32);
            $table->decimal('sale_price', 18, 6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'internal_code']);
            $table->unique(['company_id', 'id'], 'items_company_id_id_unique');
            $table->index(['company_id', 'is_active']);
            $table->foreign(['company_id', 'category_id'])->references(['company_id', 'id'])->on('categories')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE items ADD CONSTRAINT items_type_check CHECK (type IN ('PRODUCT', 'SERVICE'))");
            DB::statement('ALTER TABLE items ADD CONSTRAINT items_sale_price_nonnegative_check CHECK (sale_price >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
