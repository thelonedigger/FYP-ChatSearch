<?php

namespace App\Services\Audit\Contracts;

interface AuditableInterface
{
    public function getAuditEntityType(): string;
    public function getAuditExcludedAttributes(): array;
    public function getAuditPiiAttributes(): array;
    public function shouldAudit(): bool;
}