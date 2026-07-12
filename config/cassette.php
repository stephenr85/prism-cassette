<?php

use Rushing\PrismCassette\Support\CassetteKey;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Mode
    |--------------------------------------------------------------------------
    | 'replay' — miss throws CassetteMissException (default, safe for CI)
    | 'record' — miss calls the live provider, writes cassette, returns result
    | 'passthrough' — cassette decorator is bypassed entirely
    */
    'mode' => env('CASSETTE_MODE', 'replay'),

    /*
    |--------------------------------------------------------------------------
    | Default Store
    |--------------------------------------------------------------------------
    | Which named store to use when no group/store is specified.
    */
    'default' => 'file',

    /*
    |--------------------------------------------------------------------------
    | Named Stores
    |--------------------------------------------------------------------------
    | Mirror the filesystems.disks pattern. Each store names a driver and
    | its configuration. The 'file' driver is the git-tracked source of truth.
    |
    | Optional per-store keys:
    |   'mode'            — overrides the global mode for this store
    |   'naming_strategy' — FQCN of a NamingStrategy; defaults to ContentAddressedStrategy
    */
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => storage_path('cassettes'),
        ],

        // Single-file deployment artifact — export from 'file' via cassette:export.
        // 'artifact' => [
        //     'driver' => 'sqlite',
        //     'database' => storage_path('cassettes.sqlite'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers to Arm
    |--------------------------------------------------------------------------
    | List of Prism provider keys to intercept. '*' means all registered
    | providers. Specific keys (e.g. 'openai', 'anthropic') are overridden
    | one-by-one via PrismManager::extend().
    */
    'providers' => env('CASSETTE_PROVIDERS', '*'),

    /*
    |--------------------------------------------------------------------------
    | Key Resolver
    |--------------------------------------------------------------------------
    | FQCN of a class implementing CassetteKeyResolver. Determines how each
    | Prism request is hashed into a cassette lookup key. Swap this to change
    | the hashing strategy without touching CassetteProvider itself.
    */
    'key_resolver' => CassetteKey::class,
];
