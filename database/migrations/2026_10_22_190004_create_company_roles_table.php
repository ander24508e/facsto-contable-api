<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles que crea cada empresa. «Administrador» nace con la empresa, es fijo (is_fixed): no se edita, no se
     * desactiva y puede todo; los demás llevan sus permisos por módulo en company_role_permissions.
     */
    public function up(): void
    {
        Schema::create('company_roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('notes', 500)->nullable();
            $table->boolean('is_fixed')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE company_roles ADD CONSTRAINT company_roles_fixed_active_check CHECK (NOT is_fixed OR is_active)');
            // Un solo rol fijo por empresa.
            DB::statement('CREATE UNIQUE INDEX company_roles_one_fixed ON company_roles (company_id) WHERE is_fixed');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_roles');
    }
};
