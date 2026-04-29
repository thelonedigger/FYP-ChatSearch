<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class DataRetentionPolicy extends Model
{
    public const ACTION_DELETE = 'delete';
    public const ACTION_ANONYMIZE = 'anonymize';
    public const ACTION_ARCHIVE = 'archive';

    protected $fillable = [
        'name', 'entity_type', 'description', 'retention_days', 'retention_action',
        'conditions', 'is_active', 'priority', 'legal_basis', 'compliance_framework',
        'last_executed_at', 'last_affected_count',
    ];

    protected $casts = ['conditions' => 'array', 'is_active' => 'boolean', 'last_executed_at' => 'datetime'];

    public function scopeActive(Builder $query): Builder { return $query->where('is_active', true); }
    public function scopeForEntity(Builder $query, string $entityType): Builder { return $query->where('entity_type', $entityType); }
    public function scopeByPriority(Builder $query): Builder { return $query->orderByDesc('priority'); }
    public function getRetentionCutoffDate(): \Carbon\Carbon { return now()->subDays($this->retention_days); }
    public function markExecuted(int $affectedCount): void { $this->update(['last_executed_at' => now(), 'last_affected_count' => $affectedCount]); }
}