<?php

namespace App\Services\Documents\Processing;

use App\Jobs\ProcessDocumentJob;
use App\Models\ProcessingTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AsyncDocumentProcessingService
{
    public function __construct(private PipelineExecutorService $pipelineExecutor) {}

    public function queueDocument(string $filePath, array $options = []): ProcessingTask
    {
        if (!file_exists($filePath)) throw new \InvalidArgumentException("File not found: {$filePath}");

        $task = ProcessingTask::create([
            'filepath' => $filePath, 'filename' => basename($filePath), 'status' => ProcessingTask::STATUS_PENDING,
            'options' => $options, 'file_size' => filesize($filePath), 'max_retries' => $options['max_retries'] ?? 3,
        ]);

        $job = ProcessDocumentJob::dispatch($task)->onQueue($options['queue'] ?? 'document-processing');
        if ($options['delay'] ?? null) $job->delay($options['delay']);

        $task->markQueued();
        return $task;
    }

    public function queueBatch(array $filePaths, array $options = []): Collection
    {
        return collect($filePaths)->map(fn($path) => rescue(fn() => $this->queueDocument($path, $options), fn($e) => Log::error('Queue failed', ['path' => $path, 'error' => $e->getMessage()]) ?: null))->filter();
    }

    public function processSync(string $filePath, array $options = []): ProcessingTask
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $task = ProcessingTask::create([
            'filepath'  => $filePath,
            'filename'  => basename($filePath),
            'status'    => ProcessingTask::STATUS_PENDING,
            'options'   => $options,
            'file_size' => filesize($filePath),
        ]);

        $this->pipelineExecutor->execute($task);

        return $task->fresh();
    }

    public function retry(ProcessingTask $task): ProcessingTask
    {
        if (!$task->canRetry()) throw new \InvalidArgumentException("Cannot retry. Status: {$task->status}, Retries: {$task->retry_count}/{$task->max_retries}");
        ProcessDocumentJob::dispatch($task)->onQueue('document-processing');
        $task->markQueued();
        return $task;
    }

    public function cancel(ProcessingTask $task): ProcessingTask
    {
        if ($task->isCompleted() || $task->isProcessing()) throw new \InvalidArgumentException("Cannot cancel task with status: {$task->status}");
        $task->update(['status' => ProcessingTask::STATUS_CANCELLED, 'completed_at' => now()]);
        return $task;
    }

    public function getTask(string $taskId): ?ProcessingTask { return ProcessingTask::where('task_id', $taskId)->first(); }

    public function getTasks(array $filters = []): Collection
    {
        $q = ProcessingTask::query()->orderByDesc('created_at');
        foreach (['status', 'since' => 'created_at'] as $key => $col) {
            $k = is_int($key) ? $col : $key;
            if (isset($filters[$k])) is_int($key) ? $q->where($col, $filters[$k]) : $q->where($col, '>=', $filters[$k]);
        }
        return $q->limit($filters['limit'] ?? 50)->get();
    }

    public function getStats(): array
    {
        $stats = ProcessingTask::selectRaw("status, COUNT(*) as count, AVG(EXTRACT(EPOCH FROM (completed_at - started_at)) * 1000) as avg_duration")
            ->groupBy('status')->get()->keyBy('status');
        return array_merge(
            array_combine(['pending', 'queued', 'processing', 'completed', 'failed'], array_map(fn($s) => $stats->get($s)?->count ?? 0, [ProcessingTask::STATUS_PENDING, ProcessingTask::STATUS_QUEUED, ProcessingTask::STATUS_PROCESSING, ProcessingTask::STATUS_COMPLETED, ProcessingTask::STATUS_FAILED])),
            ['avg_duration_ms' => $stats->get(ProcessingTask::STATUS_COMPLETED)?->avg_duration ?? 0]
        );
    }

    public function cleanup(int $daysToKeep = 30): int
    {
        return ProcessingTask::where('status', ProcessingTask::STATUS_COMPLETED)->where('completed_at', '<', now()->subDays($daysToKeep))->delete();
    }
}