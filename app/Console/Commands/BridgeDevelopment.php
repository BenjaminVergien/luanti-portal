<?php

namespace App\Console\Commands;

use App\Services\BridgeDevelopment as Development;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('luanti:bridge:dev {operation : check, test or up} {--server= : Portal server record ID} {--source= : Local bridge Git checkout} {--game-source= : Reviewed Minetest Game directory}')]
#[Description('Check, test and deploy the local bridge through a private Hetzner development tunnel')]
class BridgeDevelopment extends Command
{
    public function handle(Development $development): int
    {
        $operation = $this->argument('operation');
        $source = $this->option('source') ?? config('services.bridge_development.source');
        if (! in_array($operation, ['check', 'test', 'up'], true)) {
            $this->error('Choose check, test or up.');

            return self::FAILURE;
        }
        try {
            if ($operation !== 'up') {
                $this->line($development->check($source));
            }
            if ($operation === 'check') {
                return self::SUCCESS;
            }
            $server = $this->option('server');
            if (! is_string($server) || ! ctype_digit($server) || (int) $server < 1) {
                throw new RuntimeException('Supply --server with the selected portal server record ID.');
            }
            $this->info('Running bridge '.$operation.' on portal server '.$server.'; Linux diagnostics stay on that VM.');
            $result = $development->deploy($operation, (int) $server, $source, $this->option('game-source') ?? config('services.bridge_development.game_source') ?? '');
            $this->info('Bridge '.$operation.' passed for snapshot '.substr($result['source_sha256'], 0, 12).'.');
            if ($operation === 'up') {
                $this->info('HTTPS endpoint: https://localhost:18443. Keep this command running; Ctrl+C closes the tunnel.');
                $development->tunnel((int) $server);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Bridge development failed ('.$exception::class.'); inspect private portal/VM diagnostics.');

            return self::FAILURE;
        }
    }
}
