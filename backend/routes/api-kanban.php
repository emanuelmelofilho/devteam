<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WhatsappSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\CodexSettingsController;
use App\Http\Controllers\Api\WorkerCredentialController;

Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::post('kanban/worker/codex-credential', [WorkerCredentialController::class, 'exchange'])->middleware('throttle:30,1');

Route::prefix('kanban')->middleware('auth:sanctum')->group(function () {
    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('tasks', TaskController::class);
    Route::post('tasks/{task}/retry', [TaskController::class, 'retry']);
    Route::get('logs', [ActivityLogController::class, 'index']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::get('codex-settings', [CodexSettingsController::class, 'index']);
    Route::post('codex-settings', [CodexSettingsController::class, 'store']);
    Route::get('tasks/{task}/comments', [\App\Http\Controllers\Api\TaskCommentController::class, 'index']);
    Route::post('tasks/{task}/comments', [\App\Http\Controllers\Api\TaskCommentController::class, 'store']);
    Route::get('whatsapp-settings', [WhatsappSettingsController::class, 'index']);
    Route::post('whatsapp-settings', [WhatsappSettingsController::class, 'store']);
    Route::post('whatsapp-settings/test', [WhatsappSettingsController::class, 'test']);
});
