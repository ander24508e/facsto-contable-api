<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('identification_type', 32);
            $table->string('identification', 20);
            $table->string('legal_name', 180);
            $table->string('commercial_name', 180)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'identification_type', 'identification']);
            $table->index(['company_id', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_identification_type_check CHECK (identification_type IN ('RUC', 'CEDULA', 'PASSPORT', 'FINAL_CONSUMER', 'FOREIGN_IDENTIFICATION'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
