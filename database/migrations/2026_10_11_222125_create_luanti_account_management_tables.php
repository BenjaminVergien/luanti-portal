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
            $table->json('approved_access')->nullable();
        });
        Schema::create('luanti_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_request_id')->constrained()->restrictOnDelete();
            $table->string('username', 20);
            $table->string('normalized_username', 20);
            $table->string('role');
            $table->text('initial_password');
            $table->timestamps();
            $table->unique(['world_request_id', 'normalized_username']);
        });
        Schema::create('luanti_account_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('luanti_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('luanti_world_id')->constrained()->restrictOnDelete();
            $table->string('normalized_username', 20);
            $table->json('applied_window')->nullable();
            $table->boolean('applied_admitted')->default(false);
            $table->boolean('is_provisioned')->default(false);
            $table->boolean('outcome_uncertain')->default(false);
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['luanti_world_id', 'normalized_username']);
        });
        Schema::create('luanti_account_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('luanti_world_id')->constrained()->restrictOnDelete();
            $table->string('job_id', 64)->unique();
            $table->string('operation');
            $table->string('state')->default('queued')->index();
            $table->unsignedInteger('generation');
            $table->string('base_url', 2048);
            $table->string('world_identifier');
            $table->json('account_ids');
            $table->longText('request_payload');
            $table->json('result')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('luanti_account_jobs');
        Schema::dropIfExists('luanti_account_assignments');
        Schema::dropIfExists('luanti_accounts');
        Schema::table('world_requests', function (Blueprint $table): void {
            $table->dropColumn('approved_access');
        });
    }
};
