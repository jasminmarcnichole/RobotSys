<?php

use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileControllerController;
use App\Http\Controllers\OnboardVisionController;
use App\Http\Controllers\RobotCommandController;
use App\Http\Controllers\RobotLogController;
use App\Http\Middleware\PreventStaleRobotCommands;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/mobile')->group(function (): void {
    Route::post('/login', [MobileAuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('api.mobile.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [MobileAuthController::class, 'me'])
            ->name('api.mobile.me');

        Route::post('/logout', [MobileAuthController::class, 'logout'])
            ->name('api.mobile.logout');

        Route::get('/status', [MobileControllerController::class, 'status'])
            ->middleware('ability:mobile:control')
            ->name('api.mobile.status');

        Route::get('/commands', [MobileControllerController::class, 'commands'])
            ->middleware('ability:mobile:control')
            ->name('api.mobile.commands');

        Route::post('/commands', [MobileControllerController::class, 'sendCommand'])
            ->middleware(['ability:mobile:control', 'throttle:robot-command', PreventStaleRobotCommands::class])
            ->name('api.mobile.commands.store');
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/vision/frames', [OnboardVisionController::class, 'store'])
        ->middleware(['ability:vision:ingest', 'throttle:90,1,onboard-vision-ingest'])
        ->name('api.vision.frames');

    Route::post('/robot/log', [RobotLogController::class, 'store'])
        ->middleware(['ability:robot:log', 'throttle:robot-ingest'])
        ->name('api.robot.log');

    Route::get('/robot/latest', [RobotLogController::class, 'latest'])
        ->middleware(['ability:robot:read', 'throttle:robot-read'])
        ->name('api.robot.latest');

    Route::post('/robot/command', [RobotCommandController::class, 'store'])
        ->middleware(['ability:robot:command:create', 'throttle:robot-command'])
        ->name('api.robot.command');

    Route::get('/robot/command/pending', [RobotCommandController::class, 'pending'])
        ->middleware(['ability:robot:command:read', 'throttle:robot-command'])
        ->name('api.robot.command.pending');

    Route::post('/robot/command/{robotCommand}/acknowledge', [RobotCommandController::class, 'acknowledge'])
        ->middleware(['ability:robot:command:ack', 'throttle:robot-command'])
        ->name('api.robot.command.acknowledge');

    Route::post('/robot/command/{robotCommand}/status', [RobotCommandController::class, 'updateStatus'])
        ->middleware(['ability:robot:command:ack', 'throttle:robot-command'])
        ->name('api.robot.command.status');
});
