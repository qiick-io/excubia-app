<?php

use App\Http\Controllers\Api\PushedExceptionsController;
use App\Http\Controllers\Api\PushedReportController;
use App\Http\Controllers\Api\PushedRuntimeController;
use App\Http\Middleware\AuthenticateProjectTokenMiddleware;
use App\Http\Middleware\RecordIngestRejectionMiddleware;
use Illuminate\Support\Facades\Route;

// Push endpoints for qiick/excubia-core, authenticated by the project token (never a session).
// Rejections (4xx) are counted for the System page (#117).
Route::middleware([RecordIngestRejectionMiddleware::class, 'throttle:excubia-push', AuthenticateProjectTokenMiddleware::class])->group(function () {
    Route::post('reports', [PushedReportController::class, 'store'])->name('api.reports.store');
    Route::post('runtime', [PushedRuntimeController::class, 'store'])->name('api.runtime.store');
    Route::post('exceptions', [PushedExceptionsController::class, 'store'])->name('api.exceptions.store');
});
