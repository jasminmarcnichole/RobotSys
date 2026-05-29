<?php

use App\Http\Controllers\Auth\AdminAuthenticatedSessionController;
use App\Http\Controllers\RobotDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('robot.dashboard');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/admin/login', [AdminAuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('/admin/login', [AdminAuthenticatedSessionController::class, 'store'])
        ->name('admin.login.store');
});

Route::post('/admin/logout', [AdminAuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('admin.logout');

Route::middleware(['auth', 'can:access-admin'])
    ->controller(RobotDashboardController::class)
    ->group(function (): void {
        Route::get('/dashboard', 'index')
            ->defaults('page', 'overview')
            ->name('robot.dashboard');

        Route::get('/dashboard/camera', 'index')
            ->defaults('page', 'camera')
            ->name('robot.dashboard.camera');

        Route::get('/dashboard/capabilities', 'index')
            ->defaults('page', 'capabilities')
            ->name('robot.dashboard.capabilities');

        Route::get('/dashboard/waste-sorting', 'index')
            ->defaults('page', 'waste')
            ->name('robot.dashboard.waste');

        Route::get('/dashboard/commands', 'index')
            ->defaults('page', 'commands')
            ->name('robot.dashboard.commands');

        Route::get('/dashboard/evaluation', 'index')
            ->defaults('page', 'evaluation')
            ->name('robot.dashboard.evaluation');

        Route::get('/dashboard/logs', 'index')
            ->defaults('page', 'logs')
            ->name('robot.dashboard.logs');
    });
