<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->string('role', 16);
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE company_memberships ADD CONSTRAINT company_memberships_role_check CHECK (role IN ('OWNER', 'ADMIN', 'BILLER', 'VIEWER'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_memberships');
    }
};
