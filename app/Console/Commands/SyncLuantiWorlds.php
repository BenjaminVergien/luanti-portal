<?php

namespace App\Console\Commands;

use App\Services\LuantiWorldSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('luanti:worlds:sync {--server= : Synchronize only this configured server ID}')]
#[Description('Synchronize saved Luanti maps from configured bridge APIs')]
class SyncLuantiWorlds extends Command
{
    public function handle(LuantiWorldSynchronizer $synchronizer): int
    {
        $serverId = $this->option('server');
        if ($serverId !== null && (! ctype_digit((string) $serverId) || (int) $serverId < 1)) {
            $this->error('Bitte eine gültige Server-ID angeben.');

            return self::FAILURE;
        }
        $results = $synchronizer->syncConfiguredServers($serverId !== null ? (int) $serverId : null);
        if ($serverId !== null && $results === []) {
            $this->error('Kein Server mit dieser ID und vollständiger API-Konfiguration gefunden.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($results as $id => $result) {
            $message = 'Server '.$id.': '.$result['title'].' – '.$result['body'];
            if ($result['successful']) {
                $this->info($message);
            } else {
                $this->error($message);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
