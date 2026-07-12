<?php

namespace Rushing\PrismCassette\Commands;

use Illuminate\Console\Command;
use Rushing\PrismCassette\CassetteManager;

class CassettePruneCommand extends Command
{
    protected $signature = 'cassette:prune
        {store : Store name to prune}
        {keys* : Keys to keep (everything else is removed)}';

    protected $description = 'Remove cassettes not in the provided keep-list';

    public function handle(CassetteManager $manager): int
    {
        $store = $manager->store($this->argument('store'));
        $keepKeys = $this->argument('keys');

        $allKeys = $store->keys();
        $pruned = 0;

        foreach ($allKeys as $key) {
            if (! in_array($key, $keepKeys, true)) {
                $store->forget($key);
                $pruned++;
            }
        }

        $this->info(sprintf(
            'Pruned %d cassette(s) from [%s]; %d kept.',
            $pruned,
            $this->argument('store'),
            count($keepKeys),
        ));

        return Command::SUCCESS;
    }
}
