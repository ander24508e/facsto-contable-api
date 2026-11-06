<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Derechos de la empresa sobre módulos y funciones (config/modules.php). Fuente: Condore Platform
        // (o la consola mientras Platform no exista). READ_ONLY: plan vencido o impago, se consulta sin escribir.
        Schema::create('company_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 60);
            $table->string('status', 16)->default('ENABLED');
            $table->string('source', 16)->default('CONSOLE');
            $table->timestampTz('valid_until')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'feature']);
        });

        // Última versión de derechos aplicada por empresa, con los límites del plan (se aplican más adelante).
        Schema::create('company_entitlement_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('version')->default(0);
            $table->string('subscription_reference', 120)->nullable();
            $table->json('limits')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestamps();
        });

        // Bitácora append-only de cada mensaje de derechos recibido: soporte y auditoría.
        Schema::create('entitlement_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('source', 16);
            $table->string('result', 16);
            $table->json('payload');
            $table->timestampTz('created_at');
            $table->index(['company_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE company_entitlements ADD CONSTRAINT company_entitlements_status_check CHECK (status IN ('ENABLED','READ_ONLY','DISABLED'))");
            DB::statement("ALTER TABLE company_entitlements ADD CONSTRAINT company_entitlements_source_check CHECK (source IN ('CONSOLE','PLATFORM'))");
            DB::statement("ALTER TABLE entitlement_syncs ADD CONSTRAINT entitlement_syncs_result_check CHECK (result IN ('APPLIED','STALE'))");
            DB::statement('CREATE OR REPLACE FUNCTION entitlement_syncs_append_only() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'entitlement_syncs es de solo inserción\'; END; $$ LANGUAGE plpgsql');
            DB::statement('CREATE TRIGGER entitlement_syncs_append_only BEFORE UPDATE OR DELETE ON entitlement_syncs FOR EACH ROW EXECUTE FUNCTION entitlement_syncs_append_only()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_syncs');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS entitlement_syncs_append_only()');
        }
        Schema::dropIfExists('company_entitlement_states');
        Schema::dropIfExists('company_entitlements');
    }
};
