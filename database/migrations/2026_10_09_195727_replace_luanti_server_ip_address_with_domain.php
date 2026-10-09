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
        Schema::table('luanti_servers', function (Blueprint $table) {
            $table->renameColumn('ip_address', 'domain');
        });
        Schema::table('luanti_servers', function (Blueprint $table) {
            $table->string('domain', 253)->change();
        });
    }

    /**
     * Restore the previous column name without truncating registered domains.
     */
    public function down(): void
    {
        Schema::table('luanti_servers', function (Blueprint $table) {
            $table->renameColumn('domain', 'ip_address');
        });
    }
};
