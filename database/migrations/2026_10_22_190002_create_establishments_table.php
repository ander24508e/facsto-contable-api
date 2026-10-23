<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->char('code', 3);
            $table->string('name', 120);
            $table->string('address', 255);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_main')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
