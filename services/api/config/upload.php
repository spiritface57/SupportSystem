<?php

return [
    // Test-only: keep false by default. Barrier is enabled only when explicitly requested.
    'finalize_barrier' => [
        'enabled' => env('FINALIZE_BARRIER', false),
        'timeout_ms' => env('FINALIZE_BARRIER_TIMEOUT_MS', 15000),
    ],
];
