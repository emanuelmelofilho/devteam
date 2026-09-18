<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AgentProviderController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClaudeSettingsController;
use App\Http\Controllers\Api\CodexSettingsController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskCommentController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TokenGoalController;
use App\Http\Controllers\Api\WhatsappSettingsController;
use App\Http\Controllers\Api\WorkerCredentialController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::post('kanban/worker/credential', [WorkerCredentialController::class, 'exchange'])->middleware('throttle:30,1');
// Legacy path, still used by workers installed before the provider option existed.
Route::post('kanban/worker/codex-credential', [WorkerCredentialController::class, 'exchange'])->middleware('throttle:30,1');

Route::prefix('kanban')->middleware('auth:sanctum')->group(function () {
    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('tasks', TaskController::class);
    Route::post('tasks/{task}/retry', [TaskController::class, 'retry']);
    Route::post('tasks/{task}/report', [TaskController::class, 'report']);
    Route::get('metrics/summary', [MetricsController::class, 'summary']);
    Route::put('token-goals/{provider}', [TokenGoalController::class, 'update']);
    Route::get('logs', [ActivityLogController::class, 'index']);
    Route::get('logs/{log}', [ActivityLogController::class, 'show']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::get('codex-settings', [CodexSettingsController::class, 'index']);
    Route::post('codex-settings', [CodexSettingsController::class, 'store']);
    Route::get('claude-settings', [ClaudeSettingsController::class, 'index']);
    Route::post('claude-settings', [ClaudeSettingsController::class, 'store']);
    Route::get('agent-provider', [AgentProviderController::class, 'index']);
    Route::post('agent-provider', [AgentProviderController::class, 'store']);
    Route::get('tasks/{task}/comments', [TaskCommentController::class, 'index']);
    Route::post('tasks/{task}/comments', [TaskCommentController::class, 'store']);
    Route::get('whatsapp-settings', [WhatsappSettingsController::class, 'index']);
    Route::post('whatsapp-settings', [WhatsappSettingsController::class, 'store']);
    Route::post('whatsapp-settings/test', [WhatsappSettingsController::class, 'test']);
});
