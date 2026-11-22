<?php

use Illuminate\Support\Facades\Route;
use NotificationLab\LabController;

Route::prefix(config('notification-lab.path'))->name('notification-lab.')
    ->middleware('web')->group(function (): void {
        Route::get('assets/{asset}', [LabController::class, 'asset'])
            ->whereIn('asset', ['lab.css', 'app.js'])->name('asset');
        Route::get('worker.js', [LabController::class, 'worker'])->name('worker');

        Route::middleware('can:'.config('notification-lab.gate'))
            ->group(function (): void {
                Route::get('/', [LabController::class, 'index'])->name('index');
                Route::post('browser', [LabController::class, 'browser'])->name('browser');
                Route::get('topics', [LabController::class, 'topics'])->name('topics');
                Route::post('topics', [LabController::class, 'subscribe'])->name('topics.subscribe')->middleware('throttle:30,1');
                Route::delete('topics', [LabController::class, 'unsubscribe'])->name('topics.unsubscribe')->middleware('throttle:30,1');
                Route::post('send', [LabController::class, 'send'])->name('send')->middleware('throttle:20,1');
                Route::post('broadcast/auth', [LabController::class, 'broadcastAuth'])->name('broadcast');
                Route::get('database', [LabController::class, 'database'])->name('database');
            });
    });
