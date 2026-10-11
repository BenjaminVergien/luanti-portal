<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('luanti_worlds', function (Blueprint $table): void {
            $table->boolean('is_available')->default(true);
            $table->timestamp('last_discovered_at')->nullable();
        });
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->timestamp('worlds_synced_at')->nullable();
            $table->string('world_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->dropColumn(['worlds_synced_at', 'world_sync_error']);
        });
        Schema::table('luanti_worlds', function (Blueprint $table): void {
            $table->dropColumn(['is_available', 'last_discovered_at']);
        });
    }
};
