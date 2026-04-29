<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\ProcessingTask;
use App\Services\Documents\Processing\PipelineExecutorService;
use App\Services\Documents\Processing\Exceptions\PipelineStageException;
use App\Services\Audit\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3, $timeout = 600;
    public bool $failOnTimeout = true;
    private const BASE_BACKOFF_SECONDS = 60;
    public function __construct(public ProcessingTask $task) {}
    public function uniqueId(): string { return $this->task->task_id; }

    public function handle(PipelineExecutorService $executor): void
    {
        $this->task->refresh();
        if ($this->task->isCancelled() || $this->task->isCompleted()) return;

        app(AuditService::class)->log('job.document_processing.started', AuditLog::CATEGORY_SYSTEM, 'processing_task', $this->task->id, ['task_id' => $this->task->task_id, 'filename' => $this->task->filename, 'attempt' => $this->attempts()]);

        try {
            $executor->execute($this->task);
        } catch (PipelineStageException $e) {
            Log::error('ProcessDocumentJob failed', ['task_id' => $this->task->task_id, 'stage' => $e->stage, 'error' => $e->getMessage()]);
            if ($e->retryable && $this->attempts() < $this->tries) throw $e;
            $this->fail($e);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::error('ProcessDocumentJob failed permanently', ['task_id' => $this->task->task_id, 'error' => $e?->getMessage()]);
        $this->task->markFailed($e?->getMessage() ?? 'Unknown error', $e instanceof PipelineStageException ? $e->stage : null);
        app(AuditService::class)->logFailure('job.document_processing.failed_permanently', AuditLog::CATEGORY_SYSTEM, $e?->getMessage() ?? 'Unknown error', ['task_id' => $this->task->task_id, 'filename' => $this->task->filename, 'attempts' => $this->attempts(), 'failed_stage' => $e instanceof PipelineStageException ? $e->stage : null]);
    }

    public function backoff(): int { 
        return self::BASE_BACKOFF_SECONDS * (2 ** ($this->attempts() - 1));
    }
    public function tags(): array { return ['document-processing', 'task:' . $this->task->task_id]; }
}