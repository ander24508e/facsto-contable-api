<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('ruc', 13)->nullable()->unique();
            $table->string('legal_name', 180)->nullable();
            $table->string('commercial_name', 180)->nullable();
            $table->string('main_address', 255)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
