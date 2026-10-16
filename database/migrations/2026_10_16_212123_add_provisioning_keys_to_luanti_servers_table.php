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
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->text('provisioning_private_key')->nullable();
            $table->text('provisioning_public_key')->nullable();
            $table->unsignedBigInteger('hetzner_ssh_key_id')->nullable();
            $table->json('hetzner_create_payload')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('luanti_servers')->whereNotNull('provisioning_private_key')->exists()) {
            throw new RuntimeException('Retain provisioning private keys while their VMs are in use.');
        }
        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->dropColumn(['provisioning_private_key', 'provisioning_public_key', 'hetzner_ssh_key_id', 'hetzner_create_payload']);
        });
    }
};
