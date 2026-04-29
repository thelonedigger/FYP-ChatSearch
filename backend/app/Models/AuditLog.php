<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    public const CATEGORY_DATA_ACCESS = 'data_access';
    public const CATEGORY_DATA_MODIFICATION = 'data_modification';
    public const CATEGORY_SYSTEM = 'system';
    public const CATEGORY_AUTHENTICATION = 'authentication';
    public const CATEGORY_SEARCH = 'search';
    public const CATEGORY_EXPORT = 'export';
    
    public const ACTOR_USER = 'user';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_API = 'api';
    public const ACTOR_JOB = 'job';
    
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';
    public const STATUS_DENIED = 'denied';

    protected $fillable = [
        'audit_id', 'action', 'action_category', 'entity_type', 'entity_id',
        'actor_type', 'actor_id', 'session_id', 'ip_address', 'user_agent',
        'request_method', 'request_path', 'old_values', 'new_values', 'metadata',
        'status', 'failure_reason', 'contains_pii', 'data_exported',
        'retention_expires_at', 'performed_at',
    ];

    protected $casts = [
        'old_values' => 'array', 'new_values' => 'array', 'metadata' => 'array',
        'contains_pii' => 'boolean', 'data_exported' => 'boolean',
        'retention_expires_at' => 'datetime', 'performed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn(AuditLog $log) => $log->audit_id ??= Str::uuid()->toString() and $log->performed_at ??= now());
    }

    public function scopeForEntity(Builder $query, string $entityType, ?int $entityId = null): Builder
    {
        return $entityId ? $query->where('entity_type', $entityType)->where('entity_id', $entityId) : $query->where('entity_type', $entityType);
    }

    public function scopeByActor(Builder $query, string $actorType, ?int $actorId = null): Builder
    {
        return $actorId ? $query->where('actor_type', $actorType)->where('actor_id', $actorId) : $query->where('actor_type', $actorType);
    }

    public function scopeInCategory(Builder $query, string $category): Builder { return $query->where('action_category', $category); }
    public function scopeWithAction(Builder $query, string $action): Builder { return $query->where('action', $action); }
    public function scopeExpired(Builder $query): Builder { return $query->whereNotNull('retention_expires_at')->where('retention_expires_at', '<', now()); }
    public function scopeContainingPii(Builder $query): Builder { return $query->where('contains_pii', true); }

    public function scopeInPeriod(Builder $query, $from, $to = null): Builder
    {
        return $to ? $query->where('performed_at', '>=', $from)->where('performed_at', '<=', $to) : $query->where('performed_at', '>=', $from);
    }

    public function getEntity(): ?Model
    {
        if (!$this->entity_type || !$this->entity_id) return null;
        $map = ['document' => Document::class, 'text_chunk' => TextChunk::class, 'processing_task' => ProcessingTask::class, 'user' => User::class];
        return isset($map[$this->entity_type]) ? $map[$this->entity_type]::find($this->entity_id) : null;
    }
}