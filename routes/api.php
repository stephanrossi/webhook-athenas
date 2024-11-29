<?php

use App\Http\Controllers\DocumentCenterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/webhook', [DocumentCenterController::class, 'handle']);
Route::get('/ping', [DocumentCenterController::class, 'ping']);
Route::post('/ping', [DocumentCenterController::class, 'ping']);
