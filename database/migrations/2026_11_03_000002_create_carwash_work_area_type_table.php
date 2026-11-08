<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los tipos que hace cada área de trabajo (uno o dos; la API lo valida). Las dos llaves llevan la empresa, así un
 * área nunca toma un tipo de otra empresa. Un tipo en uso no se borra: se desactiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carwash_work_area_type', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_area_id');
            $table->foreignId('area_type_id');
            $table->primary(['work_area_id', 'area_type_id']);
            $table->index(['company_id', 'area_type_id']);
            $table->foreign(['company_id', 'work_area_id'])->references(['company_id', 'id'])->on('carwash_work_areas')->cascadeOnDelete();
            $table->foreign(['company_id', 'area_type_id'])->references(['company_id', 'id'])->on('carwash_area_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_work_area_type');
    }
};
