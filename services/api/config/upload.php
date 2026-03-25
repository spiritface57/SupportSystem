<?php

return [
    // Test-only: keep false by default. Barrier is enabled only when explicitly requested.
    'finalize_barrier' => [
        'enabled' => env('FINALIZE_BARRIER', false),
        'timeout_ms' => env('FINALIZE_BARRIER_TIMEOUT_MS', 15000),
    ],

    'storage' => [
        'final_disk' => env('UPLOAD_FINAL_DISK', 'upload_final'),
        'quarantine_disk' => env('UPLOAD_QUARANTINE_DISK', 'upload_quarantine'),
        'final_prefix' => trim((string) env('UPLOAD_FINAL_PREFIX', 'uploads'), '/'),
        'quarantine_prefix' => trim((string) env('UPLOAD_QUARANTINE_PREFIX', 'uploads'), '/'),
    ],
];
