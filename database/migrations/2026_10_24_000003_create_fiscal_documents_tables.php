<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('establishment_id');
            $table->foreignId('emission_point_id');
            $table->string('document_type', 24);
            // La numeración pertenece a cada RUC: si la empresa cambia de RUC, el nuevo empieza la suya y la
            // del anterior queda intacta (y continúa si se vuelve a él).
            $table->char('issuer_ruc', 13);
            $table->unsignedBigInteger('current_number')->default(0);
            // Primer secuencial de este RUC en el punto (1, o el que continúa la numeración de otro sistema).
            $table->unsignedBigInteger('initial_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'establishment_id', 'emission_point_id', 'document_type', 'issuer_ruc'], 'document_sequences_identity_unique');
            $table->foreign(['company_id', 'establishment_id'])->references(['company_id', 'id'])->on('establishments')->restrictOnDelete();
            $table->foreign(['company_id', 'emission_point_id'])->references(['company_id', 'id'])->on('emission_points')->restrictOnDelete();
        });

        Schema::create('electronic_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id');
            $table->foreignId('customer_id');
            $table->foreignId('establishment_id');
            $table->foreignId('emission_point_id');
            $table->string('document_type', 24);
            $table->char('establishment_code', 3);
            $table->char('emission_point_code', 3);
            $table->char('sequential', 9);
            $table->char('full_number', 17);
            $table->char('numeric_code', 8);
            $table->char('access_key', 49)->unique();
            $table->date('issue_date');
            $table->string('status', 32)->default('NUMBERED');
            $table->char('issuer_ruc', 13)->nullable();
            $table->string('issuer_legal_name', 180)->nullable();
            $table->string('issuer_commercial_name', 180)->nullable();
            $table->string('issuer_main_address', 255)->nullable();
            $table->string('establishment_address', 255)->nullable();
            $table->boolean('accounting_required')->nullable();
            $table->string('special_taxpayer_number', 20)->nullable();
            $table->string('withholding_agent_number', 20)->nullable();
            $table->string('artisan_qualification_number', 30)->nullable();
            $table->string('rimpe_type', 32)->nullable();
            $table->string('sri_environment', 16)->nullable();
            $table->string('emission_type', 16)->nullable();
            $table->string('xml_version', 16)->nullable();
            $table->string('provider_ruc', 13)->nullable();
            $table->string('provider_name', 180)->nullable();
            $table->string('customer_identification_type', 32);
            $table->string('customer_identification', 20);
            $table->string('customer_name', 180);
            $table->string('customer_address', 255)->nullable();
            $table->string('customer_email', 254)->nullable();
            $table->decimal('total_without_taxes', 18, 2)->default(0);
            $table->decimal('subtotal_without_tax', 18, 2)->default(0);
            $table->decimal('subtotal_zero', 18, 2)->default(0);
            $table->decimal('subtotal_taxed', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('tip_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->char('currency', 3)->default('USD');
            // Momentos reales de cada etapa (F04-F07); authorized_at es la fecha oficial del SRI.
            $table->timestampTz('numbered_at')->nullable();
            $table->timestampTz('xml_built_at')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->string('authorization_number', 49)->nullable();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('last_sri_status_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique('sale_id');
            $table->unique(['company_id', 'issuer_ruc', 'establishment_code', 'emission_point_code', 'document_type', 'sequential'], 'electronic_documents_number_unique');
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['company_id', 'establishment_id'])->references(['company_id', 'id'])->on('establishments')->restrictOnDelete();
            $table->foreign(['company_id', 'emission_point_id'])->references(['company_id', 'id'])->on('emission_points')->restrictOnDelete();
            $table->index(['company_id', 'status', 'issue_date']);
            $table->index(['status', 'last_sri_status_at']);
        });

        Schema::create('electronic_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('electronic_document_id');
            $table->string('item_code', 25);
            $table->string('auxiliary_code', 25)->nullable();
            $table->string('description', 180);
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 6);
            $table->decimal('discount', 18, 2);
            $table->decimal('subtotal', 18, 2);
            $table->decimal('price_total_without_tax', 18, 2);
            $table->decimal('total', 18, 2);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'electronic_document_id'])->references(['company_id', 'id'])->on('electronic_documents')->cascadeOnDelete();
        });

        Schema::create('electronic_document_line_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('electronic_document_line_id');
            $table->string('tax_code', 12);
            $table->string('percentage_code', 12);
            $table->decimal('percentage', 8, 6);
            $table->decimal('taxable_base', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->timestamps();
            $table->foreign(['company_id', 'electronic_document_line_id'])->references(['company_id', 'id'])->on('electronic_document_lines')->cascadeOnDelete();
        });

        Schema::create('electronic_document_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('electronic_document_id');
            $table->string('tax_code', 12);
            $table->string('percentage_code', 12);
            $table->decimal('percentage', 8, 6);
            $table->decimal('taxable_base', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->timestamps();
            $table->foreign(['company_id', 'electronic_document_id'])->references(['company_id', 'id'])->on('electronic_documents')->cascadeOnDelete();
            $table->unique(['company_id', 'electronic_document_id', 'tax_code', 'percentage_code'], 'electronic_document_taxes_summary_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_number_check CHECK (current_number BETWEEN 0 AND 999999999)');
            DB::statement('ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_initial_check CHECK (initial_number BETWEEN 1 AND 999999999)');
            DB::statement("ALTER TABLE electronic_documents ADD CONSTRAINT electronic_documents_status_check CHECK (status IN ('DRAFT','NUMBERED','XML_BUILT','SIGNED','QUEUED','RECEIVED','RETURNED','PENDING_AUTHORIZATION','AUTHORIZED','NOT_AUTHORIZED','FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_document_taxes');
        Schema::dropIfExists('electronic_document_line_taxes');
        Schema::dropIfExists('electronic_document_lines');
        Schema::dropIfExists('electronic_documents');
        Schema::dropIfExists('document_sequences');
    }
};
