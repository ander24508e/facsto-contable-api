<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Roles de cada usuario en su empresa: uno o varios; sus permisos se suman. */
    public function up(): void
    {
        Schema::create('company_membership_roles', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['membership_id', 'role_id']);
            $table->index(['company_id', 'role_id']);
            $table->foreign(['company_id', 'membership_id'])->references(['company_id', 'id'])->on('company_memberships')->cascadeOnDelete();
            $table->foreign(['company_id', 'role_id'])->references(['company_id', 'id'])->on('company_roles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_membership_roles');
    }
};
