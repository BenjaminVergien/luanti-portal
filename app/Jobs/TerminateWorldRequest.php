<?php

namespace App\Jobs;

use App\Services\WorldRequestTermination;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TerminateWorldRequest implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public int $uniqueFor = 1300;

    public function __construct(public int $requestId) {}

    public function uniqueId(): string
    {
        return (string) $this->requestId;
    }

    /**
     * Execute the job.
     */
    public function handle(WorldRequestTermination $termination): void
    {
        $termination->process($this->requestId);
    }
}
