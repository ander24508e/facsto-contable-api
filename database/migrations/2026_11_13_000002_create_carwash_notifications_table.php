<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Bandeja de avisos de la Lavadora (Configuración › Clientes): «vehículo listo» y recordatorio de cita. Cada aviso queda
 * con su mensaje y su estado. Hoy se envía a mano por WhatsApp (el operador abre el chat con el mensaje escrito); el
 * envío automático (API oficial de WhatsApp, correo) usará esta misma bandeja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carwash_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id');
            $table->string('kind', 12);
            $table->string('channel', 12)->default('WHATSAPP');
            $table->string('recipient', 160)->nullable();
            $table->string('message', 1000);
            $table->string('status', 12)->default('PENDING');
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // One aviso of each kind per order.
            $table->unique(['work_order_id', 'kind']);
            $table->index(['company_id', 'status']);
            $table->foreign(['company_id', 'work_order_id'])->references(['company_id', 'id'])->on('carwash_work_orders')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE carwash_notifications ADD CONSTRAINT carwash_notifications_check CHECK (kind IN ('READY','REMINDER') AND channel IN ('WHATSAPP','EMAIL') AND status IN ('PENDING','OPENED','SENT','SKIPPED','FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_notifications');
    }
};
