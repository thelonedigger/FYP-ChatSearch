<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingTaskLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['processing_task_id', 'stage', 'level', 'message', 'context', 'duration_ms', 'created_at'];
    protected $casts = ['context' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn($log) => $log->created_at ??= now());
    }

    public function task(): BelongsTo { return $this->belongsTo(ProcessingTask::class, 'processing_task_id'); }
}