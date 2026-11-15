<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Evidencias de la recepción de la Lavadora: fotos y videos cortos del vehículo al recibirlo (respaldo de rayones y
 * golpes) y la firma del cliente. Se guardan en el disco privado del sistema con su huella SHA-256; nunca se editan.
 * Vencen a los 90 días (`expires_at`) salvo que la orden esté «En reclamo» (carwash_work_orders.evidence_hold).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carwash_work_order_evidences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id');
            $table->string('kind', 12);
            $table->string('path', 255);
            $table->string('mime', 80);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('note', 200)->nullable();
            // Cuándo se tomó (lo dice el dispositivo) y cuándo vence el archivo.
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'work_order_id']);
            $table->index('expires_at');
            $table->foreign(['company_id', 'work_order_id'])->references(['company_id', 'id'])->on('carwash_work_orders')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE carwash_work_order_evidences ADD CONSTRAINT carwash_evidences_kind_check CHECK (kind IN ('PHOTO','VIDEO','SIGNATURE') AND size_bytes > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_work_order_evidences');
    }
};
