<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::post('/transfers', [TransferController::class, 'store'])->middleware('throttle:transfers');
    Route::get('/transfers/{transfer}', [TransferController::class, 'show']);
});
