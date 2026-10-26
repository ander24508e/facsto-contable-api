<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emission_points', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('establishment_id');
            $table->char('code', 3);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['establishment_id', 'code']);
            $table->unique(['company_id', 'id'], 'emission_points_company_id_id_unique');
            $table->foreign(['company_id', 'establishment_id'])
                ->references(['company_id', 'id'])
                ->on('establishments')
                ->cascadeOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX emission_points_one_default_per_establishment ON emission_points (establishment_id) WHERE is_default = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('emission_points');
    }
};
