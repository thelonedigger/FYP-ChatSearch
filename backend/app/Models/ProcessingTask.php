<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProcessingTask extends Model
{
    public const STATUS_PENDING = 'pending', STATUS_QUEUED = 'queued', STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed', STATUS_FAILED = 'failed', STATUS_CANCELLED = 'cancelled';
    public const STAGE_EXTRACTION = 'extraction', STAGE_VALIDATION = 'validation', STAGE_CHUNKING = 'chunking';
    public const STAGE_EMBEDDING = 'embedding', STAGE_STORAGE = 'storage';

    protected $fillable = ['task_id', 'filepath', 'filename', 'status', 'current_stage', 'stages', 'options', 'metadata', 'document_id', 'error_message', 'error_stage', 'retry_count', 'max_retries', 'progress_percent', 'file_size', 'queued_at', 'started_at', 'completed_at'];
    protected $casts = ['stages' => 'array', 'options' => 'array', 'metadata' => 'array', 'queued_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $task) {
            $task->task_id ??= Str::uuid()->toString();
            $task->stages  ??= self::getDefaultStages();
        });
    }

    public static function getDefaultStages(): array { return array_fill_keys(self::getStageOrder(), ['status' => 'pending', 'started_at' => null, 'completed_at' => null, 'duration_ms' => null, 'error' => null]); }
    public static function getStageOrder(): array { return [self::STAGE_EXTRACTION, self::STAGE_VALIDATION, self::STAGE_CHUNKING, self::STAGE_EMBEDDING, self::STAGE_STORAGE]; }

    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function logs(): HasMany { return $this->hasMany(ProcessingTaskLog::class)->orderBy('created_at'); }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isQueued(): bool { return $this->status === self::STATUS_QUEUED; }
    public function isProcessing(): bool { return $this->status === self::STATUS_PROCESSING; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }
    public function isFailed(): bool { return $this->status === self::STATUS_FAILED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
    public function canRetry(): bool { return $this->isFailed() && $this->retry_count < $this->max_retries; }

    public function markStageStarted(string $stage): void
    {
        $s = $this->stages; $s[$stage]['status'] = 'processing'; $s[$stage]['started_at'] = now()->toISOString();
        $this->update(['stages' => $s, 'current_stage' => $stage, 'progress_percent' => $this->calcProgress($stage, 'processing')]);
    }

    public function markStageCompleted(string $stage, array $meta = []): void
    {
        $s = $this->stages;
        $s[$stage] = array_merge($s[$stage], $meta, ['status' => 'completed', 'completed_at' => now()->toISOString(), 'duration_ms' => now()->diffInMilliseconds($s[$stage]['started_at'] ?? now())]);
        $this->update(['stages' => $s, 'progress_percent' => $this->calcProgress($stage, 'completed')]);
    }

    public function markStageFailed(string $stage, string $error): void
    {
        $s = $this->stages; $s[$stage] = array_merge($s[$stage], ['status' => 'failed', 'completed_at' => now()->toISOString(), 'error' => $error]);
        $this->update(['stages' => $s, 'status' => self::STATUS_FAILED, 'error_message' => $error, 'error_stage' => $stage]);
    }

    public function markQueued(): void { $this->update(['status' => self::STATUS_QUEUED, 'queued_at' => now()]); }
    public function markStarted(): void { $this->update(['status' => self::STATUS_PROCESSING, 'started_at' => now()]); }
    public function markCompleted(int $docId): void { $this->update(['status' => self::STATUS_COMPLETED, 'document_id' => $docId, 'completed_at' => now(), 'progress_percent' => 100, 'current_stage' => null]); }
    public function markFailed(string $error, ?string $stage = null): void { $this->update(['status' => self::STATUS_FAILED, 'error_message' => $error, 'error_stage' => $stage ?? $this->current_stage, 'completed_at' => now()]); }
    public function incrementRetry(): void { $this->increment('retry_count'); }

    private function calcProgress(string $stage, string $status): int
    {
        $stages = self::getStageOrder();
        $idx = array_search($stage, $stages);
        return $idx === false ? 0 : (int) round(($idx + ($status === 'completed' ? 1 : ($status === 'processing' ? 0.5 : 0))) * (100 / count($stages)));
    }

    public function getTotalDurationMs(): ?int { return $this->started_at ? ($this->completed_at ?? now())->diffInMilliseconds($this->started_at) : null; }
    public function getStageDurations(): array { return array_filter(array_map(fn($d) => $d['duration_ms'] ?? null, $this->stages)); }
    public function getAuditEntityType(): string { return 'processing_task'; }
    public function getAuditExcludedAttributes(): array { return ['stages']; }
    public function getAuditPiiAttributes(): array { return ['filepath']; }
    public function shouldAudit(): bool { return !empty(array_intersect(array_keys($this->getDirty()), ['status', 'document_id', 'error_message'])); }
}