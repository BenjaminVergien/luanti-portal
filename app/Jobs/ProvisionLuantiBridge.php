<?php

namespace App\Jobs;

use App\Services\BridgeProvisioning;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionLuantiBridge implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 1000;

    public int $tries = 1;

    public int $uniqueFor = 1200;

    public function __construct(public int $serverId) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    /**
     * Execute the job.
     */
    public function handle(BridgeProvisioning $provisioning): void
    {
        if ($provisioning->process($this->serverId)) {
            self::dispatch($this->serverId)->delay(now()->addSeconds(30));
        }
    }
}
