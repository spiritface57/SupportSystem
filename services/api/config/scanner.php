<?php

return [
    'base_url' => env('SCANNER_BASE_URL', 'http://scanner:3001'),
    'timeout'  => (int) env('SCANNER_TIMEOUT', 10),
];
