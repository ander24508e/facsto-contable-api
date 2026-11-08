<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Cada usuario pertenece a una sola empresa (una suscripción = una empresa).
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
            // Sus roles van en company_membership_roles (llave compuesta para no cruzar empresas).
            $table->unique(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_memberships');
    }
};
