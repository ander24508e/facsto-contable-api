<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_certificates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Null for rejected certificates: their key material is deleted immediately.
            $table->string('storage_path', 500)->nullable();
            $table->text('password_ciphertext')->nullable();
            $table->string('issued_to', 255);
            $table->string('subject_identification', 64)->nullable();
            // Holder RUC read from a recognized X.509 extension; must equal the company RUC to sign.
            $table->char('subject_ruc', 13)->nullable();
            $table->string('issuer', 255);
            $table->string('serial_number', 100)->nullable();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until');
            $table->string('status', 16)->index();
            $table->string('validation_error_code', 40)->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();
            $table->index(['company_id', 'is_active', 'status']);
        });

        DB::statement('CREATE UNIQUE INDEX digital_certificates_one_active_per_company ON digital_certificates (company_id) WHERE is_active = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_certificates');
    }
};
