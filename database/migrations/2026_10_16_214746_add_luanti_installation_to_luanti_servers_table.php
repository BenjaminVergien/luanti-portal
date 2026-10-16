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
            $table->string('luanti_docker_image')->nullable();
            $table->string('luanti_installation_status')->nullable();
            $table->text('luanti_installation_error')->nullable();
            $table->string('luanti_image_id', 80)->nullable();
            $table->string('luanti_engine_version')->nullable();
            $table->timestamp('luanti_verified_at')->nullable();
            $table->text('provisioning_host_public_key')->nullable();
            $table->text('provisioning_cloud_config')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->dropColumn(['luanti_docker_image', 'luanti_installation_status', 'luanti_installation_error',
                'luanti_image_id', 'luanti_engine_version', 'luanti_verified_at', 'provisioning_host_public_key', 'provisioning_cloud_config']);
        });
    }
};
