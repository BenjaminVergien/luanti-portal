<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->dropColumn(['worlds_synced_at', 'world_sync_error']);
        });
        Schema::table('luanti_world_templates', function (Blueprint $table): void {
            $table->renameColumn('last_discovered_at', 'commissioned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('luanti_world_templates', function (Blueprint $table): void {
            $table->renameColumn('commissioned_at', 'last_discovered_at');
        });
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->timestamp('worlds_synced_at')->nullable();
            $table->string('world_sync_error')->nullable();
        });
    }
};
