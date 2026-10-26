<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada interacción con el SRI queda trazada sin tocar la identidad fiscal del comprobante.
        Schema::create('sri_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('electronic_document_id');
            $table->string('operation', 16);
            $table->unsignedInteger('attempt');
            $table->string('endpoint_environment', 16);
            $table->string('status', 32);
            $table->char('request_sha256', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['electronic_document_id', 'operation', 'attempt'], 'sri_submissions_attempt_unique');
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'electronic_document_id', 'created_at']);
            $table->foreign(['company_id', 'electronic_document_id'])
                ->references(['company_id', 'id'])
                ->on('electronic_documents')
                ->restrictOnDelete();
        });

        Schema::create('sri_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('submission_id');
            $table->string('identifier', 16)->nullable();
            $table->string('type', 32)->nullable();
            $table->string('message', 500);
            $table->text('additional_information')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->foreign(['company_id', 'submission_id'])
                ->references(['company_id', 'id'])
                ->on('sri_submissions')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sri_submissions ADD CONSTRAINT sri_submissions_operation_check CHECK (operation IN ('RECEPTION','AUTHORIZATION','QUERY'))");
            DB::statement("ALTER TABLE sri_submissions ADD CONSTRAINT sri_submissions_environment_check CHECK (endpoint_environment IN ('TEST','PRODUCTION'))");
            DB::statement("ALTER TABLE sri_submissions ADD CONSTRAINT sri_submissions_status_check CHECK (status IN ('IN_PROGRESS','RECEIVED','RETURNED','AUTHORIZED','NOT_AUTHORIZED','PENDING','BLOCKED','TRANSPORT_ERROR','RESPONSE_INVALID'))");
            DB::statement('ALTER TABLE sri_submissions ADD CONSTRAINT sri_submissions_attempt_check CHECK (attempt >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sri_messages');
        Schema::dropIfExists('sri_submissions');
    }
};
