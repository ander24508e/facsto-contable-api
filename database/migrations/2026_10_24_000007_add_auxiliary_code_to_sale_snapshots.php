<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_details', 'auxiliary_code')) {
            Schema::table('sale_details', function (Blueprint $table): void {
                $table->string('auxiliary_code', 50)->nullable()->after('item_code');
            });
        }
        if (! Schema::hasColumn('electronic_document_lines', 'auxiliary_code')) {
            Schema::table('electronic_document_lines', function (Blueprint $table): void {
                $table->string('auxiliary_code', 50)->nullable()->after('item_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('electronic_document_lines', 'auxiliary_code')) {
            Schema::table('electronic_document_lines', function (Blueprint $table): void {
                $table->dropColumn('auxiliary_code');
            });
        }
        if (Schema::hasColumn('sale_details', 'auxiliary_code')) {
            Schema::table('sale_details', function (Blueprint $table): void {
                $table->dropColumn('auxiliary_code');
            });
        }
    }
};
