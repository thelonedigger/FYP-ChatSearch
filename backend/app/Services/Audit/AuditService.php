<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Services\Audit\Contracts\AuditableInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AuditService
{
    private bool $enabled;
    private int $defaultRetentionDays;

    public function __construct()
    {
        $this->enabled = config('audit.enabled', true);
        $this->defaultRetentionDays = config('audit.default_retention_days', 365);
    }

    public function log(string $action, string $category, ?string $entityType = null, ?int $entityId = null, array $metadata = [], string $status = AuditLog::STATUS_SUCCESS): ?AuditLog
    {
        if (!$this->enabled) return null;
        
        try {
            return AuditLog::create([
                'action' => $action, 'action_category' => $category, 'entity_type' => $entityType, 'entity_id' => $entityId,
                'actor_type' => $this->determineActorType(), 'actor_id' => auth()->id(), 'session_id' => session()->getId(),
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
                'request_method' => request()->method(), 'request_path' => request()->path(),
                'metadata' => $metadata, 'status' => $status, 'retention_expires_at' => now()->addDays($this->defaultRetentionDays),
            ]);
        } catch (\Exception $e) {
            Log::error('Audit logging failed', ['error' => $e->getMessage(), 'action' => $action]);
            return null;
        }
    }

    public function logDataAccess(string $entityType, ?int $entityId, string $accessType = 'read', array $metadata = []): ?AuditLog
    {
        return $this->log("{$entityType}.{$accessType}", AuditLog::CATEGORY_DATA_ACCESS, $entityType, $entityId, $metadata);
    }

    public function logModelCreated(Model $model): ?AuditLog
    {
        $entityType = $this->getEntityType($model);
        $newValues = $this->getAuditableValues($model);
        return $this->logChange("{$entityType}.created", $entityType, $model->getKey(), null, $newValues, $this->containsPii($model, array_keys($newValues)));
    }

    public function logModelUpdated(Model $model): ?AuditLog
    {
        $dirty = $model->getDirty();
        if (empty($dirty)) return null;
        
        $entityType = $this->getEntityType($model);
        $oldValues = $this->filterExcludedAttributes($model, array_intersect_key($model->getOriginal(), $dirty));
        $newValues = $this->filterExcludedAttributes($model, $dirty);
        return $this->logChange("{$entityType}.updated", $entityType, $model->getKey(), $oldValues, $newValues, $this->containsPii($model, array_keys($dirty)));
    }

    public function logModelDeleted(Model $model): ?AuditLog
    {
        $entityType = $this->getEntityType($model);
        $oldValues = $this->getAuditableValues($model);
        return $this->logChange("{$entityType}.deleted", $entityType, $model->getKey(), $oldValues, null, $this->containsPii($model, array_keys($oldValues)));
    }

    public function logChange(string $action, string $entityType, ?int $entityId, ?array $oldValues, ?array $newValues, bool $containsPii = false, array $metadata = []): ?AuditLog
    {
        if (!$this->enabled) return null;
        
        try {
            return AuditLog::create([
                'action' => $action, 'action_category' => AuditLog::CATEGORY_DATA_MODIFICATION,
                'entity_type' => $entityType, 'entity_id' => $entityId,
                'actor_type' => $this->determineActorType(), 'actor_id' => auth()->id(), 'session_id' => session()->getId(),
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
                'request_method' => request()->method(), 'request_path' => request()->path(),
                'old_values' => $oldValues, 'new_values' => $newValues, 'metadata' => $metadata,
                'contains_pii' => $containsPii, 'retention_expires_at' => now()->addDays($this->defaultRetentionDays),
            ]);
        } catch (\Exception $e) {
            Log::error('Audit change logging failed', ['error' => $e->getMessage(), 'action' => $action]);
            return null;
        }
    }

    public function logSystemEvent(string $action, array $metadata = [], string $status = AuditLog::STATUS_SUCCESS): ?AuditLog
    {
        return $this->log($action, AuditLog::CATEGORY_SYSTEM, null, null, $metadata, $status);
    }

    public function logSearch(string $query, int $resultsCount, array $metadata = []): ?AuditLog
    {
        return $this->log('search.performed', AuditLog::CATEGORY_SEARCH, null, null, array_merge(['query' => $query, 'results_count' => $resultsCount], $metadata));
    }

    public function logExport(string $entityType, array $entityIds, string $format, array $metadata = []): ?AuditLog
    {
        if (!$this->enabled) return null;
        
        try {
            return AuditLog::create([
                'action' => "{$entityType}.exported", 'action_category' => AuditLog::CATEGORY_EXPORT, 'entity_type' => $entityType,
                'actor_type' => $this->determineActorType(), 'actor_id' => auth()->id(), 'session_id' => session()->getId(),
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
                'metadata' => array_merge(['entity_ids' => $entityIds, 'format' => $format, 'count' => count($entityIds)], $metadata),
                'data_exported' => true, 'retention_expires_at' => now()->addDays($this->defaultRetentionDays),
            ]);
        } catch (\Exception $e) {
            Log::error('Audit export logging failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function logFailure(string $action, string $category, string $reason, array $metadata = []): ?AuditLog
    {
        if (!$this->enabled) return null;
        
        try {
            return AuditLog::create([
                'action' => $action, 'action_category' => $category,
                'actor_type' => $this->determineActorType(), 'actor_id' => auth()->id(), 'session_id' => session()->getId(),
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
                'request_method' => request()->method(), 'request_path' => request()->path(),
                'metadata' => $metadata, 'status' => AuditLog::STATUS_FAILURE, 'failure_reason' => $reason,
                'retention_expires_at' => now()->addDays($this->defaultRetentionDays),
            ]);
        } catch (\Exception $e) {
            Log::error('Audit failure logging failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function determineActorType(): string
    {
        if (app()->runningInConsole()) return AuditLog::ACTOR_JOB;
        if (auth()->check()) return AuditLog::ACTOR_USER;
        return request()->hasHeader('Authorization') ? AuditLog::ACTOR_API : AuditLog::ACTOR_SYSTEM;
    }

    private function getEntityType(Model $model): string
    {
        return $model instanceof AuditableInterface ? $model->getAuditEntityType() : strtolower(class_basename($model));
    }

    private function getAuditableValues(Model $model): array
    {
        $excluded = $model instanceof AuditableInterface ? $model->getAuditExcludedAttributes() : ['password', 'remember_token', 'api_token'];
        return array_filter($model->getAttributes(), fn($key) => !in_array($key, $excluded), ARRAY_FILTER_USE_KEY);
    }

    private function filterExcludedAttributes(Model $model, array $attributes): array
    {
        $excluded = $model instanceof AuditableInterface ? $model->getAuditExcludedAttributes() : ['password', 'remember_token', 'api_token'];
        return array_filter($attributes, fn($key) => !in_array($key, $excluded), ARRAY_FILTER_USE_KEY);
    }

    private function containsPii(Model $model, array $changedKeys): bool
    {
        $piiAttrs = $model instanceof AuditableInterface ? $model->getAuditPiiAttributes() : ['email', 'name', 'ip_address', 'phone'];
        return !empty(array_intersect($changedKeys, $piiAttrs));
    }

    public function isEnabled(): bool { return $this->enabled; }
}