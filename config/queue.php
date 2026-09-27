<?php

declare(strict_types=1);

/**
 * Job queue (table frasm_jobs, worker: php bin/frasm queue:work)
 */
return [
    // Seconds after which a job reserved by a crashed worker is handed out again
    // (must be longer than the slowest job)
    'retry_after' => 300,

    // Worker defaults (overridable by CLI options)
    'worker' => [
        'sleep'     => 3,     // seconds to wait when the queue is empty
        'batch'     => 10,    // jobs reserved per query
        'max_time'  => 3600,  // daemon restarts itself after this many seconds (0 = never)
        'memory_mb' => 128,
    ],
];
