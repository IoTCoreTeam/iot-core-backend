<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ControlAnalogSignalController;
use Modules\ControlModule\Http\Controllers\ControlUrlController;
use Modules\ControlModule\Http\Controllers\GatewayController;
use Modules\ControlModule\Http\Controllers\NodeController;
use Modules\ControlModule\Http\Controllers\WorkflowController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware(['auth:api', 'admin_or_engineer'])->prefix('v1')->group(function () {

    Route::prefix('gateways')->group(function (): void {
        Route::get('/', [GatewayController::class, 'index'])->name('gateways.index');
        Route::post('/register', [GatewayController::class, 'registation'])->name('gateways.register');
        Route::post('{external_id}/deactivate', [GatewayController::class, 'deactivation'])->name('gateways.deactivate');
        Route::delete('{external_id}', [GatewayController::class, 'delete'])->name('gateways.delete');
    });

    Route::prefix('nodes')->group(function (): void {
        Route::get('/', [NodeController::class, 'index'])->name('nodes.index');
        Route::post('/register', [NodeController::class, 'registation'])->name('nodes.register');
        Route::post('{external_id}/deactivate', [NodeController::class, 'deactivation'])->name('nodes.deactivate');
    });

    Route::prefix('control-urls')->group(function (): void {
        Route::get('/', [ControlUrlController::class, 'index'])->name('control-urls.index');
        Route::post('/', [ControlUrlController::class, 'store'])->name('control-urls.store');
        Route::put('{id}', [ControlUrlController::class, 'update'])->name('control-urls.update');
        Route::post('{id}/execute', [ControlUrlController::class, 'executeControlUrl'])->name('control-urls.execute');
        Route::delete('{id}', [ControlUrlController::class, 'delete'])->name('control-urls.delete');
    });

    Route::prefix('control-analog-signals')->group(function (): void {
        Route::get('/', [ControlAnalogSignalController::class, 'index'])
            ->name('control-analog-signals.index');
        Route::post('createorupdate', [ControlAnalogSignalController::class, 'createOrUpdate'])
            ->name('control-analog-signals.createorupdate');
    });

    Route::prefix('workflows')->group(function (): void {
        Route::get('/', [WorkflowController::class, 'index'])->name('workflows.index');
        Route::get('{workflow}', [WorkflowController::class, 'show'])->name('workflows.show');
        Route::post('/', [WorkflowController::class, 'store'])->name('workflows.store');
        Route::put('{workflow}', [WorkflowController::class, 'update'])->name('workflows.update');
        Route::post('{workflow}/run', [WorkflowController::class, 'run'])->name('workflows.run');
        Route::post('{workflow}/stop', [WorkflowController::class, 'stop'])->name('workflows.stop');
        Route::get('{workflow}/run/stream', [WorkflowController::class, 'runStream'])->name('workflows.run.stream');
        Route::delete('{workflow}', [WorkflowController::class, 'destroy'])->name('workflows.delete');
    });
});

Route::get('available-nodes', [NodeController::class, 'getActiveDevices'])->name('available-nodes.index');
