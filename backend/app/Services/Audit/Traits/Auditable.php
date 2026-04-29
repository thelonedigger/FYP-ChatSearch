<?php

namespace App\Services\Audit\Traits;

use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    protected static bool $auditingEnabled = true;

    public static function bootAuditable(): void
    {
        static::created(fn(Model $model) => static::$auditingEnabled && $model->shouldAudit() && app(AuditService::class)->logModelCreated($model));
        static::updated(fn(Model $model) => static::$auditingEnabled && $model->shouldAudit() && app(AuditService::class)->logModelUpdated($model));
        static::deleted(fn(Model $model) => static::$auditingEnabled && $model->shouldAudit() && app(AuditService::class)->logModelDeleted($model));
    }

    public static function withoutAuditing(callable $callback): mixed
    {
        $prev = static::$auditingEnabled;
        static::$auditingEnabled = false;
        try { return $callback(); } finally { static::$auditingEnabled = $prev; }
    }

    public static function enableAuditing(): void { static::$auditingEnabled = true; }
    public static function disableAuditing(): void { static::$auditingEnabled = false; }
    
    public function getAuditEntityType(): string { return strtolower(class_basename($this)); }
    public function getAuditExcludedAttributes(): array { return ['password', 'remember_token', 'api_token']; }
    public function getAuditPiiAttributes(): array { return ['email', 'name', 'ip_address', 'phone']; }
    public function shouldAudit(): bool { return true; }

    public function getAuditableAttributes(): array
    {
        return array_filter($this->getAttributes(), fn($key) => !in_array($key, $this->getAuditExcludedAttributes()), ARRAY_FILTER_USE_KEY);
    }

    public function hasChangedPiiAttributes(): bool
    {
        return !empty(array_intersect(array_keys($this->getDirty()), $this->getAuditPiiAttributes()));
    }
}