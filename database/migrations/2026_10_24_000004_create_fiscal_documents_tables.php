<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emission_points', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'emission_points_company_id_id_unique');
        });
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('establishment_id');
            $table->foreignId('emission_point_id');
            $table->string('document_type', 24);
            $table->unsignedBigInteger('current_number')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'establishment_id', 'emission_point_id', 'document_type'], 'document_sequences_identity_unique');
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
            $table->string('customer_identification_type', 32);
            $table->string('customer_identification', 20);
            $table->string('customer_name', 180);
            $table->string('customer_address', 255)->nullable();
            $table->string('customer_email', 254)->nullable();
            $table->decimal('subtotal_without_tax', 18, 2)->default(0);
            $table->decimal('subtotal_zero', 18, 2)->default(0);
            $table->decimal('subtotal_taxed', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2);
            $table->char('currency', 3)->default('USD');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique('sale_id');
            $table->unique(['company_id', 'establishment_code', 'emission_point_code', 'document_type', 'sequential'], 'electronic_documents_number_unique');
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['company_id', 'establishment_id'])->references(['company_id', 'id'])->on('establishments')->restrictOnDelete();
            $table->foreign(['company_id', 'emission_point_id'])->references(['company_id', 'id'])->on('emission_points')->restrictOnDelete();
            $table->index(['company_id', 'status', 'issue_date']);
        });

        Schema::create('electronic_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('electronic_document_id');
            $table->string('item_code', 50);
            $table->string('description', 180);
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_price', 18, 6);
            $table->decimal('discount', 18, 2);
            $table->decimal('subtotal', 18, 2);
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
        Schema::table('emission_points', function (Blueprint $table): void {
            $table->dropUnique('emission_points_company_id_id_unique');
        });
    }
};
