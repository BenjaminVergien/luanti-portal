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
            $table->string('domain', 253)->nullable()->change();
            $table->uuid('hetzner_reference')->nullable()->unique();
            $table->unsignedBigInteger('hetzner_server_id')->nullable()->unique();
            $table->string('hetzner_name', 63)->nullable()->unique();
            $table->string('hetzner_status')->nullable();
            $table->string('hetzner_ipv4', 45)->nullable();
            $table->string('hetzner_ipv6', 64)->nullable();
            $table->text('hetzner_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('luanti_servers')->whereNull('domain')->exists()) {
            throw new RuntimeException('Assign domains to VM records before rolling back this migration.');
        }

        Schema::table('luanti_servers', function (Blueprint $table): void {
            $table->dropUnique(['hetzner_reference']);
            $table->dropUnique(['hetzner_server_id']);
            $table->dropUnique(['hetzner_name']);
            $table->dropColumn(['hetzner_reference', 'hetzner_server_id', 'hetzner_name', 'hetzner_status', 'hetzner_ipv4', 'hetzner_ipv6', 'hetzner_error']);
            $table->string('domain', 253)->nullable(false)->change();
        });
    }
};
