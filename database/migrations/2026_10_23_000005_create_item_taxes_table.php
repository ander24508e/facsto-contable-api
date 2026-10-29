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
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id');
            $table->foreignId('company_tax_id');
            $table->timestamps();
            $table->unique(['item_id', 'company_tax_id']);
            // Composite keys: an item can only use taxes enabled for its own company.
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->cascadeOnDelete();
            $table->foreign(['company_id', 'company_tax_id'])->references(['company_id', 'id'])->on('company_taxes')->restrictOnDelete();
        });

        // Tarifas por defecto de una categoría: los artículos nuevos de la categoría las heredan.
        Schema::create('category_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id');
            $table->foreignId('company_tax_id');
            $table->timestamps();
            $table->unique(['category_id', 'company_tax_id']);
            $table->foreign(['company_id', 'category_id'])->references(['company_id', 'id'])->on('categories')->cascadeOnDelete();
            $table->foreign(['company_id', 'company_tax_id'])->references(['company_id', 'id'])->on('company_taxes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_taxes');
        Schema::dropIfExists('item_taxes');
    }
};
