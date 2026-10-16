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
        if (DB::table('world_requests')->exists() || DB::table('luanti_worlds')->exists()) {
            throw new RuntimeException('Run luanti:worlds:reset-development before migrating. Existing saves are not templates.');
        }
        Schema::rename('luanti_worlds', 'luanti_world_templates');
        Schema::table('luanti_world_templates', function (Blueprint $table): void {
            $table->renameColumn('world_identifier', 'template_identifier');
            $table->string('revision', 64)->nullable();
            $table->boolean('is_available')->default(false)->change();
        });
        Schema::table('world_requests', function (Blueprint $table): void {
            $table->renameColumn('luanti_world_id', 'luanti_world_template_id');
        });
        Schema::create('luanti_game_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('luanti_world_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('luanti_server_id')->constrained()->restrictOnDelete();
            $table->string('instance_identifier', 32)->unique();
            $table->string('template_identifier', 32);
            $table->string('template_revision', 64);
            $table->string('state')->default('preparing');
            $table->boolean('is_prepared')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::table('world_requests', function (Blueprint $table): void {
            $table->foreignId('current_game_instance_id')->nullable()->constrained('luanti_game_instances')->restrictOnDelete();
        });
        Schema::table('luanti_account_assignments', function (Blueprint $table): void {
            $table->dropUnique(['luanti_world_id', 'normalized_username']);
            $table->dropConstrainedForeignId('luanti_world_id');
            $table->foreignId('luanti_game_instance_id')->constrained()->restrictOnDelete();
            $table->unique(['luanti_game_instance_id', 'normalized_username']);
        });
        Schema::table('luanti_account_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('luanti_world_id');
            $table->foreignId('luanti_game_instance_id')->constrained()->restrictOnDelete();
            $table->renameColumn('world_identifier', 'instance_identifier');
        });
        Schema::create('luanti_lifecycle_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('luanti_game_instance_id')->constrained()->restrictOnDelete();
            $table->string('job_id', 64)->unique();
            $table->string('operation');
            $table->string('state')->default('queued');
            $table->string('base_url', 2048);
            $table->text('request_payload');
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Restore a database backup to roll back this development schema transition.');
    }
};
