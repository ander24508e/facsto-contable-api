<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('tax_type', 16);
            $table->string('sri_code', 12);
            $table->string('percentage_code', 12);
            $table->string('name', 120);
            $table->decimal('percentage', 8, 6);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tax_type', 'sri_code', 'percentage_code', 'valid_from'], 'taxes_catalog_version_unique');
            $table->index(['is_active', 'valid_from', 'valid_until']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE taxes ADD CONSTRAINT taxes_validity_check CHECK (valid_until IS NULL OR valid_until >= valid_from)');
            DB::statement('ALTER TABLE taxes ADD CONSTRAINT taxes_percentage_range_check CHECK (percentage >= 0 AND percentage <= 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
