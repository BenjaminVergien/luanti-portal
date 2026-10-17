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
            $table->boolean('public_https_enabled')->default(false);
            $table->string('dns_zone')->default('bensfoundry.de');
            $table->text('dns_api_token')->nullable();
            $table->string('acme_email')->nullable();
        });
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->string('public_hostname')->nullable()->unique();
            $table->text('public_https_configuration')->nullable();
            $table->string('dns_status')->nullable();
            $table->text('dns_error')->nullable();
            $table->string('dns_ipv4', 45)->nullable();
            $table->unsignedBigInteger('dns_action_id')->nullable();
            $table->boolean('dns_cleanup_pending')->default(false);
            $table->unsignedInteger('dns_cleanup_attempts')->default(0);
            $table->timestamp('dns_cleanup_next_at')->nullable();
            $table->string('https_status')->nullable();
            $table->text('https_error')->nullable();
            $table->unsignedInteger('public_provisioning_attempts')->default(0);
            $table->timestamp('public_provisioning_next_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->dropUnique(['public_hostname']);
            $table->dropColumn(['public_hostname', 'public_https_configuration', 'dns_status', 'dns_error', 'dns_ipv4',
                'dns_action_id', 'dns_cleanup_pending', 'dns_cleanup_attempts', 'dns_cleanup_next_at', 'https_status',
                'https_error', 'public_provisioning_attempts', 'public_provisioning_next_at']);
        });
        Schema::table('portal_settings', function (Blueprint $table): void {
            $table->dropColumn(['public_https_enabled', 'dns_zone', 'dns_api_token', 'acme_email']);
        });
    }
};
