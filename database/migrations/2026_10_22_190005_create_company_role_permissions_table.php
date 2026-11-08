<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Permisos de un rol: la clave «módulo.acción» del catálogo (config/permissions.php y los módulos). */
    public function up(): void
    {
        Schema::create('company_role_permissions', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->string('permission', 80);
            $table->primary(['role_id', 'permission']);
            $table->foreign(['company_id', 'role_id'])->references(['company_id', 'id'])->on('company_roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_role_permissions');
    }
};
