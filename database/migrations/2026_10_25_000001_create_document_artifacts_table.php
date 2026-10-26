<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('electronic_document_id');
            $table->string('type', 32);
            $table->unsignedInteger('version')->default(1);
            $table->string('disk', 32);
            $table->string('path', 500);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 100);
            $table->timestamps();
            $table->unique(['electronic_document_id', 'type', 'version'], 'document_artifact_version_unique');
            $table->foreign(['company_id', 'electronic_document_id'])
                ->references(['company_id', 'id'])
                ->on('electronic_documents')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_artifacts');
    }
};
