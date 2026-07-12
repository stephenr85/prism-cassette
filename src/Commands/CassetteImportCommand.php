<?php

namespace Rushing\PrismCassette\Commands;

use Illuminate\Console\Command;
use Rushing\PrismCassette\CassetteManager;

class CassetteImportCommand extends Command
{
    protected $signature = 'cassette:import {source : Source store name} {target : Target store name}';

    protected $description = 'Import cassettes from one store into another (no LLM calls)';

    public function handle(CassetteManager $manager): int
    {
        $source = $manager->store($this->argument('source'));
        $target = $manager->store($this->argument('target'));

        $keys = $source->keys();

        foreach ($keys as $key) {
            $target->put($key, $source->get($key));
        }

        $this->info(sprintf(
            'Imported %d cassette(s) from [%s] → [%s].',
            count($keys),
            $this->argument('source'),
            $this->argument('target'),
        ));

        return Command::SUCCESS;
    }
}
