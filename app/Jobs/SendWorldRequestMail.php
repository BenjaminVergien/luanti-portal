<?php

namespace App\Jobs;

use App\Services\WorldRequestMailings;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWorldRequestMail implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 45;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public int $mailId) {}

    public function uniqueId(): string
    {
        return (string) $this->mailId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Execute the job.
     */
    public function handle(WorldRequestMailings $mailings): void
    {
        $mailings->deliver($this->mailId);
    }
}
