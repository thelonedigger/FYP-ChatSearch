<?php

return [
    'enabled' => env('AUDIT_ENABLED', true),
    'default_retention_days' => env('AUDIT_RETENTION_DAYS', 365),
    'always_audit' => ['document.created', 'document.deleted', 'processing_task.completed', 'processing_task.failed'],
    'never_audit' => ['health_check'],
    'audit_categories' => ['data_access', 'data_modification', 'system', 'authentication', 'search', 'export'],
    'pii_fields' => ['email', 'name', 'ip_address', 'phone', 'address', 'user_agent'],
    'excluded_fields' => ['password', 'remember_token', 'api_token', 'secret'],
    'compliance_frameworks' => ['GDPR', 'CCPA', 'HIPAA', 'SOC2'],
    'legal_bases' => ['consent', 'contract', 'legal_obligation', 'vital_interests', 'public_task', 'legitimate_interests'],
    'retention_actions' => ['delete', 'anonymize', 'archive'],
];