<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avisos firmados al módulo de origen cuando su comprobante se autoriza, se devuelve o no se autoriza.
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('api_client_id');
            $table->foreignId('electronic_document_id');
            $table->string('event', 40);
            $table->json('payload');
            $table->string('status', 16)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'api_client_id', 'created_at']);
            $table->foreign(['company_id', 'api_client_id'])->references(['company_id', 'id'])->on('api_clients')->cascadeOnDelete();
            $table->foreign(['company_id', 'electronic_document_id'])->references(['company_id', 'id'])->on('electronic_documents')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('PENDING','DELIVERED','FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
