<?php

namespace App\Console\Commands;

use App\Models\WorldRequest;
use App\Services\LuantiGameInstances;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('luanti:games:reconcile')]
#[Description('Prepare and reconcile approved private game copies')]
class ReconcileLuantiGames extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LuantiGameInstances $instances): int
    {
        foreach (WorldRequest::query()->whereNotNull('approved_access')->lazyById() as $request) {
            $instances->reconcile($request->id);
        }

        return self::SUCCESS;
    }
}
