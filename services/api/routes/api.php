<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadInitController;
use App\Http\Controllers\UploadChunkController;
use App\Http\Controllers\UploadFinalizeController;

Route::post('/upload/init', UploadInitController::class);
Route::post('/upload/chunk', UploadChunkController::class);
Route::post('/upload/finalize', UploadFinalizeController::class);
