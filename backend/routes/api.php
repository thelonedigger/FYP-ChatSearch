<?php

use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Search\SearchController;
use App\Http\Controllers\Documents\EntityController;
use App\Http\Controllers\Documents\ProcessingController;
use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\Audit\AuditController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\LlmSettingsController;
use App\Http\Controllers\Auth\DevLoginController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\Admin\ChunkingSettingsController;
use App\Http\Controllers\Admin\DocumentUploadController;
use App\Http\Controllers\Admin\PromptSettingsController;

Route::prefix('v1')->group(function () {
    Route::get('entities', [EntityController::class, 'index']);
    Route::get('entities/{entityType}', [EntityController::class, 'show']);
    Route::apiResource('documents', DocumentController::class)->only(['index', 'show', 'destroy']);
    Route::post('documents/process', [DocumentController::class, 'processFile']);
    Route::get('stats', [DocumentController::class, 'unifiedStats']);
    Route::get('documents-stats', [DocumentController::class, 'stats']);
    Route::post('search', [SearchController::class, 'search']);
    Route::post('documents/{document}/search', [SearchController::class, 'searchInDocument']);
    Route::post('search/suggestions', [SearchController::class, 'suggestions']);
    Route::post('conversation/search', [SearchController::class, 'conversationalSearch']);
    Route::post('/conversation/search/stream', [SearchController::class, 'streamConversationalSearch']);
    Route::post('conversation/clear', [SearchController::class, 'clearConversation']);
    Route::get('conversation/history', [SearchController::class, 'getConversationHistory']);

    Route::prefix('processing')->group(function () {
        Route::post('queue', [ProcessingController::class, 'queue']);
        Route::post('queue-batch', [ProcessingController::class, 'queueBatch']);
        Route::get('tasks', [ProcessingController::class, 'index']);
        Route::get('tasks/{taskId}', [ProcessingController::class, 'status']);
        Route::post('tasks/{taskId}/retry', [ProcessingController::class, 'retry']);
        Route::post('tasks/{taskId}/cancel', [ProcessingController::class, 'cancel']);
        Route::get('stats', [ProcessingController::class, 'stats']);
    });

    Route::prefix('analytics')->group(function () {
        Route::get('dashboard', [AnalyticsController::class, 'dashboard']);
        Route::get('search-performance', [AnalyticsController::class, 'searchPerformance']);
        Route::get('query-popularity', [AnalyticsController::class, 'queryPopularity']);
        Route::get('response-times', [AnalyticsController::class, 'responseTimeHistogram']);
        Route::get('intent-distribution', [AnalyticsController::class, 'intentDistribution']);
        Route::get('time-series', [AnalyticsController::class, 'timeSeries']);
        Route::get('interactions', [AnalyticsController::class, 'interactionStats']);
        Route::post('interactions', [AnalyticsController::class, 'recordInteraction']);
    });

    Route::prefix('audit')->group(function () {
        Route::get('logs', [AuditController::class, 'index']);
        Route::get('logs/{entityType}/{entityId}', [AuditController::class, 'forEntity']);
        Route::get('summary', [AuditController::class, 'summary']);
        Route::get('retention/policies', [AuditController::class, 'retentionPolicies']);
        Route::get('retention/status', [AuditController::class, 'retentionStatus']);
        Route::post('retention/policies', [AuditController::class, 'createRetentionPolicy']);
        Route::put('retention/policies/{policy}', [AuditController::class, 'updateRetentionPolicy']);
    });

    Route::prefix('admin')->group(function () {
        Route::get('/llm-settings', [LlmSettingsController::class, 'show']);
        Route::put('/llm-settings', [LlmSettingsController::class, 'update']);
        Route::get('/ollama/status', [LlmSettingsController::class, 'ollamaStatus']);
        Route::post('/ollama/warm', [LlmSettingsController::class, 'warmUpModel']);
        Route::post('/ollama/unload', [LlmSettingsController::class, 'unloadModel']);
        Route::get('/chunking-settings', [ChunkingSettingsController::class, 'show']);
        Route::put('/chunking-settings', [ChunkingSettingsController::class, 'update']);
        Route::post('/documents/upload', [DocumentUploadController::class, 'upload']);
        Route::get('/documents/supported-types', [DocumentUploadController::class, 'supportedTypes']);
        Route::get('/documents/{document}/chunks', [DocumentUploadController::class, 'chunks']);
        Route::get('/processing-tasks', [DocumentUploadController::class, 'tasks']);
        Route::get('/prompt-settings', [PromptSettingsController::class, 'show']);
        Route::put('/prompt-settings', [PromptSettingsController::class, 'update']);
        Route::post('/prompt-settings/reset', [PromptSettingsController::class, 'reset']);
    });

    if (app()->environment('local')) {
        Route::prefix('auth')->group(function () {
            Route::get('/users', [DevLoginController::class, 'users']);
            Route::post('/login', [DevLoginController::class, 'login']);
            Route::post('/logout', [DevLoginController::class, 'logout'])->middleware('auth:sanctum');
        });
    }

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', fn (\Illuminate\Http\Request $request) => $request->user());

        Route::apiResource('conversations', ConversationController::class);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'storeMessage']);
    });
});