<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcessingTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'task_id' => $this->task_id, 'filename' => $this->filename, 'status' => $this->status,
            'progress_percent' => $this->progress_percent, 'current_stage' => $this->current_stage, 'stages' => $this->stages,
            'file_size' => $this->file_size, 'options' => $this->options, 'metadata' => $this->metadata,
            'document_id' => $this->document_id, 'document' => new DocumentResource($this->whenLoaded('document')),
            'error_message' => $this->when($this->isFailed(), $this->error_message),
            'error_stage' => $this->when($this->isFailed(), $this->error_stage),
            'retry_count' => $this->retry_count, 'max_retries' => $this->max_retries, 'can_retry' => $this->canRetry(),
            'total_duration_ms' => $this->getTotalDurationMs(), 'stage_durations' => $this->getStageDurations(),
            'logs' => ProcessingTaskLogResource::collection($this->whenLoaded('logs')),
            'queued_at' => $this->queued_at?->toISOString(), 'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(), 'created_at' => $this->created_at->toISOString(),
        ];
    }
}