<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipos de área de la Lavadora (Lavado, Secado, Lubricación y los que cree la empresa). Los tres primeros vienen
 * fijos (`code`): se crean solos por empresa y no se renombran ni se desactivan. Un área hace uno o dos tipos
 * (carwash_work_area_type) y un servicio de Tarifas puede pedir uno (carwash_services.area_type_id). Se crea antes
 * que las tablas de la Lavadora porque los servicios apuntan aquí. No se eliminan: se desactivan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carwash_area_types', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10)->nullable();
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE carwash_area_types ADD CONSTRAINT carwash_area_types_code_check CHECK (code IS NULL OR (code IN ('WASH','DRY','LUBE') AND is_active))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_area_types');
    }
};
