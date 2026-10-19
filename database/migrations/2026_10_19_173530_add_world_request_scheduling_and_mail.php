<?php

use Carbon\CarbonImmutable;
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
        Schema::table('world_requests', function (Blueprint $table): void {
            $table->timestamp('provisioning_starts_at')->nullable();
            $table->unsignedInteger('approval_mail_revision')->nullable();
            $table->foreignId('facilitator_account_id')->nullable()->constrained('luanti_accounts')->restrictOnDelete();
        });
        Schema::create('world_request_mails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_request_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 80);
            $table->string('kind', 16);
            $table->json('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['world_request_id', 'event_key']);
        });
        DB::table('world_requests')->whereNotNull('provisioning_configuration')->whereNull('approved_access')
            ->orderBy('id')->each(function (object $request): void {
                DB::table('world_requests')->where('id', $request->id)->update([
                    'provisioning_starts_at' => CarbonImmutable::parse(substr($request->start_date, 0, 10), 'Europe/Berlin')
                        ->startOfDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('world_request_mails');
        Schema::table('world_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('facilitator_account_id');
            $table->dropColumn(['provisioning_starts_at', 'approval_mail_revision']);
        });
    }
};
