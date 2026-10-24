<?php

declare(strict_types=1);

return [
    // Use the consuming application's connection; never a package development database.
    'connection' => null,
    // null detects the resolved connection driver. An explicit value must match it.
    'driver' => null,
    'target_instance' => '',
    'plan_directory' => null,

    'engines' => [
        'pgsql' => [
            'schemas' => ['public'],
            'migration_table' => 'migrations',
            'lock_timeout_ms' => 5000,
            // Apply SQL execution limit; 0 permits hour-long statements without a package deadline.
            'statement_timeout_ms' => 0,
        ],
        'mysql' => [
            'migration_table' => 'migrations',
            // Metadata lock wait only. MySQL does not provide PostgreSQL's DDL statement timeout.
            'lock_timeout_ms' => 5000,
        ],
    ],

    // Full trusted migration replay in fresh namespaces on the configured database server.
    'replay' => [
        'migration_paths' => [],
        'timeout' => 180,
    ],
];
