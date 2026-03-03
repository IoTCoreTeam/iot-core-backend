<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ManagedAreaController;
use App\Http\Controllers\SystemLogController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('login', [AuthController::class, 'login']);
Route::post('refresh-token', [AuthController::class, 'refreshToken']);

Route::middleware('auth:api')->group(function () {
    Route::get('user', [AuthController::class, 'user']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('change-password', [AuthController::class, 'changePassword']);
});

Route::middleware('auth:api')->group(function () {
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users/filter', [UserController::class, 'filter']);
    Route::put('/users/{id}', [UserController::class, 'update']);

    Route::get('/managed-areas', [ManagedAreaController::class, 'index']);
    Route::post('/managed-areas', [ManagedAreaController::class, 'store']);
    Route::put('/managed-areas/{id}', [ManagedAreaController::class, 'update']);
    Route::delete('/managed-areas/{id}', [ManagedAreaController::class, 'destroy']);

    Route::get('/company', [CompanyController::class, 'index']);
    Route::put('/company', [CompanyController::class, 'update']);
});

Route::middleware(['auth:api', 'admin'])->group(function () {
    Route::get('system-logs', [SystemLogController::class, 'index']);
});

Route::middleware(['auth:api'])->group(function () {
    Route::get('metrics/system-logs-count', [SystemLogController::class, 'countByWeekAndLevel']);
});
