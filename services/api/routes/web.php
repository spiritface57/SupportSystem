<?php

use Illuminate\Support\Facades\Route;

if (app()->environment(['local', 'testing'])) {
    Route::get('/', function () {
        return view('welcome');
    });
    Route::get('/_sleep', function () {
        $t0 = microtime(true);
        sleep(2);
        return response()->json([
            'pid' => getmypid(),
            't' => microtime(true) - $t0,
        ]);
    });
}
