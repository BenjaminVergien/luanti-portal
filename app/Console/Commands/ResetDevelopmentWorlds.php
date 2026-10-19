<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('luanti:worlds:reset-development {--force : Confirm removal of development world requests and account mappings}')]
#[Description('Reset development world records without touching server saves, users, or organizations')]
class ResetDevelopmentWorlds extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! app()->environment('local') || ! $this->option('force')) {
            $this->error('Only available in APP_ENV=local with --force.');

            return self::FAILURE;
        }
        DB::transaction(function (): void {
            foreach (['jobs', 'failed_jobs'] as $queueTable) {
                if (Schema::hasTable($queueTable)) {
                    DB::table($queueTable)->where('payload', 'like', '%ProcessLuantiAccounts%')->delete();
                    DB::table($queueTable)->where('payload', 'like', '%SendWorldRequestMail%')->delete();
                    DB::table($queueTable)->where('payload', 'like', '%ProvisionWorldRequest%')->delete();
                }
            }
            if (Schema::hasColumn('world_requests', 'current_game_instance_id')) {
                DB::table('world_requests')->update(['current_game_instance_id' => null]);
            }
            if (Schema::hasColumn('world_requests', 'facilitator_account_id')) {
                DB::table('world_requests')->update(['facilitator_account_id' => null]);
            }
            if (Schema::hasColumn('luanti_servers', 'world_request_id')) {
                DB::table('luanti_servers')->update(['world_request_id' => null]);
            }
            foreach (['world_request_mails', 'luanti_lifecycle_jobs', 'luanti_account_jobs', 'luanti_account_assignments', 'luanti_accounts', 'luanti_game_instances', 'world_requests', 'luanti_world_templates', 'luanti_worlds'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        });
        $this->info('Development world records reset. Server saves, users, organizations, and servers were preserved.');

        return self::SUCCESS;
    }
}
