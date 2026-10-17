<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('luanti_world_templates', function (Blueprint $table): void {
            $table->unsignedBigInteger('luanti_server_id')->nullable()->change();
            $table->string('library_identifier', 32)->nullable()->unique();
            $table->string('bundle_sha256', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('luanti_world_templates')->whereNull('luanti_server_id')->exists()) {
            throw new RuntimeException('Export or remove portal library entries before rolling back this migration.');
        }
        Schema::table('luanti_world_templates', function (Blueprint $table): void {
            $table->dropUnique(['library_identifier']);
            $table->dropColumn(['library_identifier', 'bundle_sha256']);
            $table->unsignedBigInteger('luanti_server_id')->nullable(false)->change();
        });
    }
};
