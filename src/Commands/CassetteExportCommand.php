<?php

namespace Rushing\PrismCassette\Commands;

use Illuminate\Console\Command;
use Rushing\PrismCassette\CassetteManager;

class CassetteExportCommand extends Command
{
    protected $signature = 'cassette:export {source : Source store name} {target : Target store name}';

    protected $description = 'Export all cassettes from one store to another (no LLM calls)';

    public function handle(CassetteManager $manager): int
    {
        $source = $manager->store($this->argument('source'));
        $target = $manager->store($this->argument('target'));

        $keys = $source->keys();

        foreach ($keys as $key) {
            $target->put($key, $source->get($key));
        }

        $this->info(sprintf(
            'Exported %d cassette(s) from [%s] → [%s].',
            count($keys),
            $this->argument('source'),
            $this->argument('target'),
        ));

        return Command::SUCCESS;
    }
}
