<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            // Identificador de la suscripción y futuro subdominio: {slug}.condoresoftware.com
            $table->string('slug', 63)->unique();
            $table->string('ruc', 13)->nullable()->unique();
            $table->string('legal_name', 180)->nullable();
            $table->string('commercial_name', 180)->nullable();
            $table->string('main_address', 255)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_ruc_format_check CHECK (ruc ~ '^[0-9]{13}$')");
            DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_slug_format_check CHECK (slug ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
