<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProcessingTaskResource;
use App\Models\AuditLog;
use App\Models\ProcessingTask;
use App\Services\Documents\Processing\AsyncDocumentProcessingService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProcessingController extends Controller
{
    public function __construct(private AsyncDocumentProcessingService $processingService, private AuditService $auditService) {}

    public function queue(Request $request): JsonResponse
    {
        $request->validate(['filepath' => 'required|string', 'options' => 'sometimes|array', 'options.force' => 'sometimes|boolean', 'options.max_retries' => 'sometimes|integer|min:1|max:10', 'options.queue' => 'sometimes|string']);
        $path = $request->input('filepath');

        if (!file_exists($path) || !is_readable($path)) {
            $this->auditService->logFailure('document.import.queued', AuditLog::CATEGORY_DATA_MODIFICATION, 'File not found or not readable', ['filepath' => $path]);
            return response()->json(['error' => 'File not found or not readable', 'filepath' => $path], 404);
        }

        $task = $this->processingService->queueDocument($path, $request->input('options', []));
        $this->auditService->log('document.import.queued', AuditLog::CATEGORY_DATA_MODIFICATION, 'processing_task', $task->id, ['task_id' => $task->task_id, 'filepath' => $path, 'filename' => $task->filename, 'file_size' => $task->file_size, 'options' => $request->input('options', [])]);
        return response()->json(['message' => 'Document queued', 'task' => new ProcessingTaskResource($task)], 202);
    }

    public function queueBatch(Request $request): JsonResponse
    {
        $request->validate(['filepaths' => 'required|array|min:1', 'filepaths.*' => 'required|string', 'options' => 'sometimes|array']);
        $paths = $request->input('filepaths');
        $tasks = $this->processingService->queueBatch($paths, $request->input('options', []));
        $this->auditService->log('document.import.batch_queued', AuditLog::CATEGORY_DATA_MODIFICATION, null, null, ['total_requested' => count($paths), 'total_queued' => $tasks->count(), 'task_ids' => $tasks->pluck('task_id')->toArray(), 'filenames' => $tasks->pluck('filename')->toArray(), 'options' => $request->input('options', [])]);
        return response()->json(['message' => 'Batch queued', 'total_requested' => count($paths), 'total_queued' => $tasks->count(), 'tasks' => ProcessingTaskResource::collection($tasks)], 202);
    }

    public function status(string $taskId): JsonResponse
    {
        $task = ProcessingTask::where('task_id', $taskId)->with('logs')->first();
        return $task ? response()->json(['task' => new ProcessingTaskResource($task)]) : response()->json(['error' => 'Task not found', 'task_id' => $taskId], 404);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => 'sometimes|string|in:pending,queued,processing,completed,failed,cancelled', 'limit' => 'sometimes|integer|min:1|max:100', 'since' => 'sometimes|date']);
        return ProcessingTaskResource::collection($this->processingService->getTasks(['status' => $request->input('status'), 'limit' => $request->input('limit', 50), 'since' => $request->input('since')]));
    }

    public function retry(string $taskId): JsonResponse
    {
        $task = ProcessingTask::where('task_id', $taskId)->first();
        if (!$task) return response()->json(['error' => 'Task not found'], 404);
        if (!$task->canRetry()) {
            $this->auditService->logFailure('document.import.retry_rejected', AuditLog::CATEGORY_DATA_MODIFICATION, 'Task cannot be retried', ['task_id' => $taskId, 'status' => $task->status, 'retry_count' => $task->retry_count]);
            return response()->json(['error' => 'Cannot retry', 'status' => $task->status, 'retry_count' => $task->retry_count, 'max_retries' => $task->max_retries], 400);
        }
        $retried = $this->processingService->retry($task);
        $this->auditService->log('document.import.retried', AuditLog::CATEGORY_DATA_MODIFICATION, 'processing_task', $task->id, ['task_id' => $taskId, 'retry_count' => $retried->retry_count, 'previous_error' => $task->error_message]);
        return response()->json(['message' => 'Task queued for retry', 'task' => new ProcessingTaskResource($retried)], 202);
    }

    public function cancel(string $taskId): JsonResponse
    {
        $task = ProcessingTask::where('task_id', $taskId)->first();
        if (!$task) return response()->json(['error' => 'Task not found'], 404);
        try {
            $cancelled = $this->processingService->cancel($task);
            $this->auditService->log('document.import.cancelled', AuditLog::CATEGORY_DATA_MODIFICATION, 'processing_task', $task->id, ['task_id' => $taskId, 'filename' => $task->filename, 'previous_status' => $task->status]);
            return response()->json(['message' => 'Task cancelled', 'task' => new ProcessingTaskResource($cancelled)]);
        } catch (\InvalidArgumentException $e) { return response()->json(['error' => $e->getMessage(), 'status' => $task->status], 400); }
    }

    public function stats(): JsonResponse { return response()->json(['stats' => $this->processingService->getStats(), 'generated_at' => now()->toISOString()]); }
}