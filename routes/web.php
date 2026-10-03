<?php

use App\Http\Controllers\Imports\CancelImportController;
use App\Http\Controllers\Imports\DownloadImportErrorsController;
use App\Http\Controllers\Imports\ImportController;
use App\Http\Controllers\Imports\ImportStatusController;
use App\Http\Controllers\Imports\RetryImportController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::get('imports/create', [ImportController::class, 'create'])->name('imports.create');
    Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:imports')->name('imports.store');
    Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::get('imports/{import}/status', ImportStatusController::class)->name('imports.status');
    Route::get('imports/{import}/errors.csv', DownloadImportErrorsController::class)->name('imports.errors');
    Route::post('imports/{import}/cancel', CancelImportController::class)->name('imports.cancel');
    Route::post('imports/{import}/retry', RetryImportController::class)->name('imports.retry');
});

require __DIR__.'/settings.php';
