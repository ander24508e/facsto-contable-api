<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Módulos (Taller, Lavadora…) que facturan con FACSTO por API: un token por módulo y empresa.
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('module', 40);
            // Sólo el SHA-256 del token; el token completo se muestra una vez al crearlo.
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 16);
            $table->string('webhook_url', 500)->nullable();
            // Cifrado con la APP_KEY: firma HMAC de los webhooks.
            $table->text('webhook_secret');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_used_at')->nullable();
            // Usuario en cuyo nombre actúa el módulo (auditoría y created_by de las ventas).
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
