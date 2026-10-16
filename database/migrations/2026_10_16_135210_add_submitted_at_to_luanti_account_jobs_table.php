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
        Schema::table('luanti_account_jobs', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable();
        });

        /** Existing active jobs may already have reached the bridge, even when still queued. */
        DB::table('luanti_account_jobs')->whereIn('state', ['queued', 'pending', 'unknown'])
            ->update(['submitted_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('luanti_account_jobs', function (Blueprint $table): void {
            $table->dropColumn('submitted_at');
        });
    }
};
