<?php

namespace Rushing\PrismCassette\Commands;

use Illuminate\Console\Command;
use Rushing\PrismCassette\CassetteManager;

class CassetteStatusCommand extends Command
{
    protected $signature = 'cassette:status';

    protected $description = 'Show cassette arming state, resolved modes, and store configuration';

    public function handle(CassetteManager $manager): int
    {
        $this->line('Global mode:  '.config('cassette.mode', 'record'));
        $this->line('Environment:  '.app()->environment());

        $armed = $manager->armedProviders();

        if ($armed === []) {
            $this->warn('Armed:        none — scopes that record or replay will throw CassetteDisarmedException');
        } else {
            $this->line('Armed:        '.implode(', ', $armed));
        }

        $this->newLine();
        $this->line('Stores:');

        foreach (config('cassette.stores', []) as $name => $store) {
            $location = $store['path'] ?? $store['database'] ?? '?';
            $this->line(sprintf(
                '  %s  driver=%s  resolved mode=%s  %s',
                $name,
                $store['driver'] ?? '?',
                $manager->resolveMode($name),
                $location,
            ));
        }

        return self::SUCCESS;
    }
}
