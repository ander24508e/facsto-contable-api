<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_certificates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('storage_path', 500);
            $table->text('password_ciphertext');
            $table->string('issued_to', 255);
            $table->string('issuer', 255);
            $table->string('serial_number', 100)->nullable();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until');
            $table->string('status', 16)->index();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();
            $table->index(['company_id', 'is_active', 'status']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('digital_certificates');
    }
};
