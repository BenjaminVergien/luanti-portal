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
        Schema::table('portal_settings', function (Blueprint $table): void {
            $table->boolean('bridge_enabled')->default(false);
            $table->string('bridge_repository')->default('Stewie23/luanti-connect');
            $table->string('bridge_ref')->default('main');
            $table->text('bridge_github_token')->nullable();
            $table->text('bridge_game_source')->nullable();
            $table->string('bridge_admin_name')->default('Administrator');
        });
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->text('bridge_configuration')->nullable();
            $table->string('bridge_commit', 40)->nullable();
            $table->string('bridge_source_sha256', 64)->nullable();
            $table->string('bridge_status')->nullable();
            $table->text('bridge_error')->nullable();
            $table->text('bridge_ca_certificate')->nullable();
            $table->timestamp('bridge_installed_at')->nullable();
            $table->foreignId('bridge_requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bridge_requested_by');
            $table->dropColumn(['bridge_configuration', 'bridge_commit', 'bridge_source_sha256', 'bridge_status', 'bridge_error', 'bridge_ca_certificate', 'bridge_installed_at']);
        });
        Schema::table('portal_settings', function (Blueprint $table): void {
            $table->dropColumn(['bridge_enabled', 'bridge_repository', 'bridge_ref', 'bridge_github_token', 'bridge_game_source', 'bridge_admin_name']);
        });
    }
};
