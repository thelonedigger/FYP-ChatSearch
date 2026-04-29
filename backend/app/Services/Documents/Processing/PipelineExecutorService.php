<?php

namespace App\Services\Documents\Processing;

use App\Models\AuditLog;
use App\Models\ProcessingTask;
use App\Models\ProcessingTaskLog;
use App\Services\Documents\Processing\Contracts\PipelineStageInterface;
use App\Services\Documents\Processing\DTOs\PipelineContext;
use App\Services\Documents\Processing\Exceptions\PipelineStageException;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Log;

class PipelineExecutorService
{
    private array $stages = [];

    public function __construct(
        Stages\ExtractionStage $ext, Stages\ValidationStage $val, Stages\ChunkingStage $chunk,
        Stages\EmbeddingStage $emb, Stages\StorageStage $store, private AuditService $auditService,
    ) {
        $this->stages = array_combine(ProcessingTask::getStageOrder(), [$ext, $val, $chunk, $emb, $store]);
    }

    public function execute(ProcessingTask $task): PipelineContext
    {
        $task->markStarted();
        $context = new PipelineContext($task);
        $completedStages = [];

        $this->auditService->logSystemEvent('pipeline.started', ['task_id' => $task->task_id, 'filename' => $task->filename, 'file_size' => $task->file_size]);

        try {
            foreach (ProcessingTask::getStageOrder() as $name) {
                if (!($stage = $this->stages[$name] ?? null)) continue;
                if ($stage->shouldSkip($context)) { $this->log($task, $name, 'info', 'Stage skipped'); continue; }
                $context = $this->executeStage($task, $stage, $context);
                $completedStages[] = $name;
            }

            $task->markCompleted($context->documentId);
            $this->log($task, 'pipeline', 'info', 'Pipeline completed', ['document_id' => $context->documentId, 'duration_ms' => $task->getTotalDurationMs()]);
            $this->auditService->log('pipeline.completed', AuditLog::CATEGORY_SYSTEM, 'document', $context->documentId, [
                'task_id' => $task->task_id, 'filename' => $task->filename, 'total_chunks' => count($context->chunks),
                'duration_ms' => $task->getTotalDurationMs(), 'stages_completed' => $completedStages,
            ]);
            return $context;
        } catch (PipelineStageException $e) {
            $this->handleFailure($task, $e, $context, $completedStages);
            throw $e;
        } catch (\Exception $e) {
            $task->markFailed($e->getMessage());
            $this->log($task, 'pipeline', 'error', 'Unexpected error: ' . $e->getMessage());
            $this->auditService->logFailure('pipeline.error', AuditLog::CATEGORY_SYSTEM, $e->getMessage(), ['task_id' => $task->task_id, 'filename' => $task->filename, 'exception_class' => get_class($e)]);
            throw $e;
        }
    }

    private function executeStage(ProcessingTask $task, PipelineStageInterface $stage, PipelineContext $context): PipelineContext
    {
        $name = $stage->getName();
        $start = microtime(true);
        $task->markStageStarted($name);

        try {
            $context = $stage->process($context);
            $task->markStageCompleted($name, $context->getStageData($name));
            $this->log($task, $name, 'info', 'Stage completed', ['duration_ms' => round((microtime(true) - $start) * 1000, 2)]);
            return $context;
        } catch (PipelineStageException $e) {
            $task->markStageFailed($name, $e->getMessage());
            $this->log($task, $name, 'error', $e->getMessage(), ['retryable' => $e->retryable]);
            throw $e;
        }
    }

    private function handleFailure(ProcessingTask $task, PipelineStageException $e, PipelineContext $context, array $completed): void
    {
        Log::error('Pipeline stage failed', ['task_id' => $task->task_id, 'stage' => $e->stage, 'error' => $e->getMessage()]);
        $this->auditService->logFailure('pipeline.stage_failed', AuditLog::CATEGORY_SYSTEM, $e->getMessage(), [
            'task_id' => $task->task_id, 'filename' => $task->filename, 'failed_stage' => $e->stage,
            'completed_stages' => $completed, 'retryable' => $e->retryable,
        ]);

        if ($task->options['rollback_on_failure'] ?? false) {
            foreach (array_reverse($completed) as $name) {
                try { $this->stages[$name]?->rollback($context); $this->log($task, $name, 'info', 'Stage rolled back'); }
                catch (\Exception $re) { $this->log($task, $name, 'warning', 'Rollback failed: ' . $re->getMessage()); }
            }
        }
    }

    private function log(ProcessingTask $task, string $stage, string $level, string $msg, array $ctx = []): void
    {
        ProcessingTaskLog::create(['processing_task_id' => $task->id, 'stage' => $stage, 'level' => $level, 'message' => $msg, 'context' => $ctx ?: null]);
    }

    public function resume(ProcessingTask $task): PipelineContext
    {
        if (!$task->isFailed()) throw new \InvalidArgumentException('Can only resume failed tasks');
        $task->incrementRetry();
        $task->update(['status' => ProcessingTask::STATUS_PROCESSING, 'error_message' => null, 'error_stage' => null]);
        return $this->execute($task);
    }
}