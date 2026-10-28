<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table): void {
            $table->string('address', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('establishments')->whereNull('address')->update(['address' => '']);
        Schema::table('establishments', function (Blueprint $table): void {
            $table->string('address', 255)->default('')->change();
        });
    }
};
