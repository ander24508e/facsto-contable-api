<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_catalog_metadata', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('barcode', 64)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'item_id']);
            $table->unique(['company_id', 'barcode']);
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->cascadeOnDelete();
            $table->foreign(['company_id', 'brand_id'])->references(['company_id', 'id'])->on('brands')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_catalog_metadata');
    }
};
