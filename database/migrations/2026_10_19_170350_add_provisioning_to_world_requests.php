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
        Schema::table('world_requests', function (Blueprint $table): void {
            $table->json('template_snapshot')->nullable();
            $table->json('provisioning_configuration')->nullable();
            $table->text('provisioning_error')->nullable();
        });
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->foreignId('world_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('world_request_id');
        });
        Schema::table('world_requests', function (Blueprint $table): void {
            $table->dropColumn(['template_snapshot', 'provisioning_configuration', 'provisioning_error']);
        });
    }
};
