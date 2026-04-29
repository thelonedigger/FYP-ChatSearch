<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\DataRetentionPolicy;
use App\Models\Document;
use App\Models\SearchMetric;
use App\Models\ProcessingTask;
use Illuminate\Support\Facades\Log;

class DataRetentionService
{
    private array $entityModelMap = [
        'audit_log' => AuditLog::class, 'document' => Document::class,
        'search_metric' => SearchMetric::class, 'processing_task' => ProcessingTask::class,
    ];

    public function __construct(private AuditService $auditService) {}

    public function executeAllPolicies(): array
    {
        $results = [];
        foreach (DataRetentionPolicy::active()->byPriority()->get() as $policy) {
            try {
                $results[$policy->name] = $this->executePolicy($policy);
            } catch (\Exception $e) {
                Log::error('Retention policy execution failed', ['policy' => $policy->name, 'error' => $e->getMessage()]);
                $results[$policy->name] = ['error' => $e->getMessage(), 'affected' => 0];
            }
        }
        return $results;
    }

    public function executePolicy(DataRetentionPolicy $policy): array
    {
        $modelClass = $this->entityModelMap[$policy->entity_type] ?? throw new \InvalidArgumentException("Unknown entity type: {$policy->entity_type}");
        $cutoffDate = $policy->getRetentionCutoffDate();
        $query = $modelClass::where('created_at', '<', $cutoffDate);
        
        if (!empty($policy->conditions)) {
            foreach ($policy->conditions as $field => $value) {
                if ($field !== 'anonymize_fields') {
                    is_array($value) ? $query->whereIn($field, $value) : $query->where($field, $value);
                }
            }
        }

        $affectedCount = match ($policy->retention_action) {
            DataRetentionPolicy::ACTION_DELETE => $this->executeDelete($query),
            DataRetentionPolicy::ACTION_ANONYMIZE => $this->executeAnonymize($query, $policy),
            DataRetentionPolicy::ACTION_ARCHIVE => $this->executeArchive($query, $policy),
            default => 0,
        };

        $policy->markExecuted($affectedCount);
        $this->auditService->logSystemEvent('retention_policy.executed', [
            'policy_id' => $policy->id, 'policy_name' => $policy->name, 'entity_type' => $policy->entity_type,
            'action' => $policy->retention_action, 'affected_count' => $affectedCount, 'cutoff_date' => $cutoffDate->toISOString(),
        ]);

        return ['policy' => $policy->name, 'action' => $policy->retention_action, 'affected' => $affectedCount, 'cutoff_date' => $cutoffDate->toISOString()];
    }

    public function purgeExpiredAuditLogs(): int
    {
        $count = AuditLog::expired()->count();
        if ($count > 0) {
            AuditLog::expired()->delete();
            $this->auditService->logSystemEvent('audit_logs.purged', ['count' => $count]);
        }
        return $count;
    }

    public function getRetentionStatus(): array
    {
        $status = [];
        foreach ($this->entityModelMap as $entityType => $modelClass) {
            $policy = DataRetentionPolicy::forEntity($entityType)->active()->byPriority()->first();
            $status[$entityType] = [
                'total_records' => $modelClass::count(), 'policy_active' => $policy !== null,
                'policy_name' => $policy?->name, 'retention_days' => $policy?->retention_days,
                'retention_action' => $policy?->retention_action, 'last_executed' => $policy?->last_executed_at?->toISOString(),
                'last_affected' => $policy?->last_affected_count ?? 0,
            ];
            if ($policy) {
                $status[$entityType]['records_past_retention'] = $modelClass::where('created_at', '<', $policy->getRetentionCutoffDate())->count();
            }
        }
        return $status;
    }

    private function executeDelete($query): int
    {
        $count = 0;
        $query->chunkById(1000, function ($records) use (&$count) {
            foreach ($records as $record) { $record->delete(); $count++; }
        });
        return $count;
    }

    private function executeAnonymize($query, DataRetentionPolicy $policy): int
    {
        $fields = $policy->conditions['anonymize_fields'] ?? ['ip_address', 'user_agent', 'session_id'];
        return $query->update(array_fill_keys($fields, '[ANONYMIZED]'));
    }

    private function executeArchive($query, DataRetentionPolicy $policy): int
    {
        Log::info('Archive action not fully implemented - records marked only', ['policy' => $policy->name]);
        return $query->update(['metadata->archived' => true, 'metadata->archived_at' => now()]);
    }

    public function registerEntityType(string $entityType, string $modelClass): void
    {
        $this->entityModelMap[$entityType] = $modelClass;
    }
}