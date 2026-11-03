<?php

use App\Http\Controllers\Api\PushedReportController;
use App\Http\Middleware\AuthenticateProjectToken;
use Illuminate\Support\Facades\Route;

// Push endpoints for qiick/excubia-core, authenticated by the project token (never a session).
Route::middleware(['throttle:excubia-push', AuthenticateProjectToken::class])->group(function () {
    Route::post('reports', [PushedReportController::class, 'store'])->name('api.reports.store');
});
