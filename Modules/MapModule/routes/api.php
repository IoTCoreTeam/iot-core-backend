<?php

use Illuminate\Support\Facades\Route;
use Modules\MapModule\Http\Controllers\ManagedAreaController;
use Modules\MapModule\Http\Controllers\ManagedAreaNodeController;
use Modules\MapModule\Http\Controllers\RouteController;

Route::middleware(['auth:api', 'admin_or_engineer'])->group(function () {
    Route::get('/managed-areas', [ManagedAreaController::class, 'index']);
    Route::post('/managed-areas', [ManagedAreaController::class, 'store']);
    Route::put('/managed-areas/{id}', [ManagedAreaController::class, 'update']);
    Route::delete('/managed-areas/{id}', [ManagedAreaController::class, 'destroy']);
    Route::post('/managed-areas/nodes/{external_id}', [ManagedAreaNodeController::class, 'sync']);
    Route::post('/map/nearest-path', [RouteController::class, 'findNearestPath']);

});
