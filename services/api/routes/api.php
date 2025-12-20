<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadInitController;


Route::post('/upload/init', UploadInitController::class);
