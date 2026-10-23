<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_document_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('electronic_document_id');
            $table->string('method_code', 12);
            $table->string('method_name', 120);
            $table->decimal('amount', 18, 2);
            $table->decimal('term', 12, 2)->nullable();
            $table->string('time_unit', 16)->nullable();
            $table->string('reference', 120)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'electronic_document_id'])->references(['company_id', 'id'])->on('electronic_documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_document_payment_methods');
    }
};
