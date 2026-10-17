<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PortalWorldTemplates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('luanti:templates:import {archive : Published template ZIP} {--actor= : Global administrator ID}')]
#[Description('Import a published world template into the private portal library')]
class ImportWorldTemplate extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PortalWorldTemplates $templates): int
    {
        if (! ctype_digit((string) $this->option('actor'))) {
            $this->error('Bitte --actor mit der ID eines globalen Administrators angeben.');

            return self::FAILURE;
        }
        $template = $templates->import(User::query()->findOrFail((int) $this->option('actor')), $this->argument('archive'));
        $this->info($template->name.' im Portal gespeichert: '.$template->revision);

        return self::SUCCESS;
    }
}
