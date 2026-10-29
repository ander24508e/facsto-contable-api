<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // F07: entrega del comprobante autorizado. Un envío jamás se simula: su estado lo dice explícitamente.
        Schema::create('document_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('electronic_document_id');
            $table->string('channel', 16);
            // Destinatarios del último envío, separados por coma.
            $table->string('recipient', 1000)->nullable();
            $table->string('status', 40);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['electronic_document_id', 'channel'], 'document_deliveries_channel_unique');
            $table->foreign(['company_id', 'electronic_document_id'])
                ->references(['company_id', 'id'])
                ->on('electronic_documents')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE document_deliveries ADD CONSTRAINT document_deliveries_channel_check CHECK (channel IN ('EMAIL'))");
            DB::statement("ALTER TABLE document_deliveries ADD CONSTRAINT document_deliveries_status_check CHECK (status IN ('PENDING','SENT','FAILED','SKIPPED_SMTP_NOT_CONFIGURED','SKIPPED_NO_RECIPIENT'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_deliveries');
    }
};
