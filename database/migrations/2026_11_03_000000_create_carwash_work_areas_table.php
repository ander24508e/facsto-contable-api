<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Áreas de trabajo de la Lavadora: uno o dos tipos de área (carwash_work_area_type), cuántos vehículos atiende a
 * la vez y su horario. Las órdenes reservadas apuntan aquí (carwash_work_orders.work_area_id), por eso esta tabla
 * se crea antes que las demás de la Lavadora. No se eliminan: se desactivan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carwash_work_areas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->time('opens_at')->default('08:00');
            $table->time('closes_at')->default('18:00');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE carwash_work_areas ADD CONSTRAINT carwash_work_areas_hours_check CHECK (closes_at > opens_at AND capacity BETWEEN 1 AND 50)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_work_areas');
    }
};
