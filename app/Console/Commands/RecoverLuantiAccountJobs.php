<?php

namespace App\Console\Commands;

use App\Jobs\ProcessLuantiAccounts;
use App\Models\WorldRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('luanti:accounts:recover')]
#[Description('Nimmt gespeicherte Spielaccount-Aufträge nach Worker-Unterbrechungen wieder auf')]
class RecoverLuantiAccountJobs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        WorldRequest::query()->whereHas('luantiAccounts')->lazyById()->each(fn (WorldRequest $request) => ProcessLuantiAccounts::dispatch($request->id));

        return self::SUCCESS;
    }
}
