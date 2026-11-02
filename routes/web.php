<?php

use App\Http\Controllers\Auth\AdminAuthenticatedSessionController;
use App\Http\Controllers\CameraDetectionController;
use App\Http\Controllers\RobotCommandController;
use App\Http\Controllers\RobotDashboardController;
use App\Http\Controllers\RobotInsightsController;
use App\Http\Controllers\RobotLogController;
use App\Http\Controllers\RobotLogExportController;
use App\Http\Controllers\OnboardVisionController;
use App\Http\Middleware\PreventStaleRobotCommands;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('robot.dashboard');
});

Route::view('/manual', 'auth.manual')->name('manual');

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
    ->group(function (): void {
        Route::get('/dashboard/logs/export', RobotLogExportController::class)
            ->middleware('throttle:10,1,robot-log-export')
            ->name('robot.dashboard.logs.export');

        Route::controller(RobotDashboardController::class)
            ->group(function (): void {
                Route::get('/dashboard', 'index')
                    ->defaults('page', 'overview')
                    ->name('robot.dashboard');

                Route::get('/dashboard/control', 'index')
                    ->defaults('page', 'control')
                    ->name('robot.dashboard.control');

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

        Route::prefix('/dashboard/api')->group(function (): void {
            Route::get('/vision/latest', [OnboardVisionController::class, 'latest'])
                ->middleware('throttle:120,1,onboard-vision-read')->name('dashboard.vision.latest');
            Route::get('/vision/frames/{onboardVisionFrame}/image', [OnboardVisionController::class, 'image'])
                ->middleware('throttle:180,1,onboard-vision-image')->name('dashboard.vision.image');

            Route::get('/insights', RobotInsightsController::class)
                ->middleware('throttle:60,1')->name('dashboard.robot.insights');
            Route::post('/control/command', [RobotCommandController::class, 'store'])
                ->middleware(['throttle:robot-command', PreventStaleRobotCommands::class])
                ->name('dashboard.robot.control');

            Route::get('/camera/detections', [CameraDetectionController::class, 'index'])
                ->name('dashboard.camera.history');

            Route::get('/camera/snapshot', [CameraDetectionController::class, 'snapshot'])
                ->middleware('throttle:120,1,robot-camera-snapshot')
                ->name('dashboard.camera.snapshot');

            Route::post('/camera/detections', [CameraDetectionController::class, 'store'])
                ->middleware('throttle:30,1,robot-camera-save')
                ->name('dashboard.camera.detections');

            Route::get('/robot/latest', [RobotLogController::class, 'latest'])
                ->name('dashboard.robot.latest');

            Route::post('/robot/log', [RobotLogController::class, 'store'])
                ->name('dashboard.robot.log');

            Route::post('/robot/command', [RobotCommandController::class, 'store'])
                ->name('dashboard.robot.command');

            Route::get('/robot/commands', [RobotCommandController::class, 'index'])
                ->name('dashboard.robot.commands');
        });
    });
