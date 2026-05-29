<?php

use App\Http\Controllers\RobotDashboardController;
use Illuminate\Support\Facades\Route;

Route::post('/robot/log', [RobotDashboardController::class, 'store'])->name('api.robot.log');
Route::get('/robot/latest', [RobotDashboardController::class, 'latest'])->name('api.robot.latest');

Route::post('/robot/command', [RobotDashboardController::class, 'sendCommand'])->name('api.robot.command');
Route::get('/robot/command/pending', [RobotDashboardController::class, 'getPendingCommand'])->name('api.robot.command.pending');
Route::post('/robot/command/{id}/acknowledge', [RobotDashboardController::class, 'acknowledgeCommand'])->name('api.robot.command.acknowledge');
