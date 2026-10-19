<?php

namespace App\Jobs;

use App\Models\WorldRequest;
use App\Services\LuantiGameInstances;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileLuantiGame implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public function __construct(public int $requestId) {}

    public function uniqueId(): string
    {
        return (string) $this->requestId;
    }

    /**
     * Execute the job.
     */
    public function handle(LuantiGameInstances $instances): void
    {
        if (WorldRequest::query()->whereKey($this->requestId)->whereNotNull('approved_access')->exists()) {
            $instances->reconcile($this->requestId);
        }
    }
}
