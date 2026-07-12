<?php

namespace Rushing\PrismCassette\Commands;

use Illuminate\Console\Command;
use Rushing\PrismCassette\CassetteManager;

class CassetteVerifyCommand extends Command
{
    protected $signature = 'cassette:verify
        {store : Store name to verify against}
        {keys* : Expected cassette keys (one or more)}';

    protected $description = 'Assert that every expected cassette key exists in the store (CI gate)';

    public function handle(CassetteManager $manager): int
    {
        $store = $manager->store($this->argument('store'));
        $expected = $this->argument('keys');

        $missing = array_values(array_filter($expected, fn ($key) => ! $store->has($key)));

        if ($missing !== []) {
            foreach ($missing as $key) {
                $this->error("Missing cassette: {$key}");
            }

            $this->line(sprintf('%d of %d cassette(s) missing.', count($missing), count($expected)));

            return Command::FAILURE;
        }

        $this->info(sprintf('All %d cassette(s) verified in [%s].', count($expected), $this->argument('store')));

        return Command::SUCCESS;
    }
}
