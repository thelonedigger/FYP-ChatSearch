<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DataRetentionPolicy;
use App\Services\Audit\DataRetentionService;
use Illuminate\Console\Command;

class AuditRetentionCommand extends Command
{
    protected $signature = 'audit:retention {--policy= : Execute specific policy} {--purge-expired} {--status} {--dry-run}';
    protected $description = 'Execute data retention policies';

    public function __construct(private DataRetentionService $retentionService) { parent::__construct(); }

    public function handle(): int
    {
        if ($this->option('status')) return $this->showStatus();
        if ($this->option('purge-expired')) return $this->purgeExpired();
        if ($policyName = $this->option('policy')) return $this->executeSpecificPolicy($policyName);
        return $this->executeAllPolicies();
    }

    private function showStatus(): int
    {
        $this->info("Data Retention Status\n" . str_repeat('=', 50));
        foreach ($this->retentionService->getRetentionStatus() as $entityType => $info) {
            $this->newLine();
            $this->line("<comment>{$entityType}</comment>");
            $this->line("  Total Records: {$info['total_records']}");
            if ($info['policy_active']) {
                $this->line("  Policy: {$info['policy_name']} | Retention: {$info['retention_days']} days ({$info['retention_action']})");
                $this->line("  Records Past Retention: " . ($info['records_past_retention'] ?? 'N/A') . " | Last Executed: " . ($info['last_executed'] ?? 'Never'));
            } else {
                $this->warn("  No active retention policy");
            }
        }
        return self::SUCCESS;
    }

    private function purgeExpired(): int
    {
        if ($this->option('dry-run')) {
            $this->info("Would purge " . AuditLog::expired()->count() . " expired audit log(s)");
            return self::SUCCESS;
        }
        $this->info("Purged {$this->retentionService->purgeExpiredAuditLogs()} expired audit log(s)");
        return self::SUCCESS;
    }

    private function executeSpecificPolicy(string $policyName): int
    {
        $policy = DataRetentionPolicy::where('name', $policyName)->first();
        if (!$policy) { $this->error("Policy not found: {$policyName}"); return self::FAILURE; }

        if ($this->option('dry-run')) {
            $this->info("Would execute: {$policy->name} ({$policy->entity_type}, {$policy->retention_action}, cutoff: {$policy->getRetentionCutoffDate()->toDateTimeString()})");
            return self::SUCCESS;
        }

        $result = $this->retentionService->executePolicy($policy);
        $this->info("Completed: {$result['affected']} record(s) affected");
        return self::SUCCESS;
    }

    private function executeAllPolicies(): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run - policies that would be executed:');
            $this->table(['Name', 'Entity Type', 'Action', 'Days'], DataRetentionPolicy::active()->byPriority()->get()->map(fn($p) => [$p->name, $p->entity_type, $p->retention_action, $p->retention_days]));
            return self::SUCCESS;
        }

        $this->info('Executing all active retention policies...');
        $this->table(['Policy', 'Action', 'Affected', 'Cutoff'], collect($this->retentionService->executeAllPolicies())->map(fn($r, $n) => [$n, $r['action'] ?? 'error', $r['affected'] ?? 0, $r['cutoff_date'] ?? ($r['error'] ?? 'N/A')]));
        return self::SUCCESS;
    }
}