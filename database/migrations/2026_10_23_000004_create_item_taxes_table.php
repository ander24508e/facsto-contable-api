<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['item_id', 'tax_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_taxes');
    }
};
