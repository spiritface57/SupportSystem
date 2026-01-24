<?php

return [
    'finalize_barrier' => (bool) env('FINALIZE_BARRIER', false),
    'finalize_barrier_timeout_ms' => (int) env('FINALIZE_BARRIER_TIMEOUT_MS', 15000),
];
