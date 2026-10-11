<?php

namespace App\Jobs;

use App\Models\LuantiAccountJob;
use App\Services\LuantiAccounts;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessLuantiAccounts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 45;

    public int $uniqueFor = 120;

    public function __construct(public int $requestId) {}

    public function uniqueId(): string
    {
        return (string) $this->requestId;
    }

    /**
     * Execute the job.
     */
    public function handle(LuantiAccounts $accounts): void
    {
        $accounts->reconcile($this->requestId);
        $job = LuantiAccountJob::query()->where('world_request_id', $this->requestId)->whereIn('state', ['queued', 'pending'])->first();
        if ($job !== null) {
            $accounts->process($job->id);
            if ($job->fresh()->state !== 'unknown') {
                self::dispatch($this->requestId)->delay(now()->addSeconds(30));
            }
        }
    }
}
