<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Carbon\Carbon;

class AuditReportCommand extends Command
{
    protected $signature = 'audit:report {--period=24h} {--category=} {--entity=} {--actor=} {--export=}';
    protected $description = 'Generate audit trail reports';

    public function handle(): int
    {
        $since = $this->parsePeriod($this->option('period'));
        $query = AuditLog::where('performed_at', '>=', $since)->orderByDesc('performed_at');

        if ($category = $this->option('category')) $query->inCategory($category);
        if ($entity = $this->option('entity')) $query->where('entity_type', $entity);
        if ($actor = $this->option('actor')) $query->where('actor_id', $actor);

        $logs = $query->get();
        return ($format = $this->option('export')) ? $this->exportReport($logs, $format) : $this->displayReport($logs, $since);
    }

    private function displayReport($logs, Carbon $since): int
    {
        $this->info("Audit Report - Since {$since->toDateTimeString()}\n" . str_repeat('=', 60) . "\n");
        $this->line("<comment>Summary</comment> Total: {$logs->count()} | Success: " . $logs->where('status', 'success')->count() . " | Failed: " . $logs->where('status', 'failure')->count() . " | PII: " . $logs->where('contains_pii', true)->count());

        $this->newLine();
        $this->line("<comment>By Category</comment>");
        foreach ($logs->groupBy('action_category')->map->count() as $cat => $count) $this->line("  {$cat}: {$count}");

        $this->newLine();
        $this->line("<comment>Top Actions</comment>");
        foreach ($logs->groupBy('action')->map->count()->sortDesc()->take(10) as $action => $count) $this->line("  {$action}: {$count}");

        $this->newLine();
        $this->line("<comment>Recent Events</comment>");
        $this->table(['Time', 'Action', 'Entity', 'Actor', 'Status'], $logs->take(10)->map(fn($l) => [
            $l->performed_at->format('Y-m-d H:i:s'), $l->action,
            $l->entity_type ? "{$l->entity_type}:{$l->entity_id}" : '-',
            "{$l->actor_type}" . ($l->actor_id ? ":{$l->actor_id}" : ''), $l->status,
        ]));

        return self::SUCCESS;
    }

    private function exportReport($logs, string $format): int
    {
        $path = storage_path("app/audit_report_" . now()->format('Y-m-d_His') . ".{$format}");
        $data = $logs->map(fn($l) => [
            'audit_id' => $l->audit_id, 'performed_at' => $l->performed_at->toISOString(), 'action' => $l->action,
            'action_category' => $l->action_category, 'entity_type' => $l->entity_type, 'entity_id' => $l->entity_id,
            'actor_type' => $l->actor_type, 'actor_id' => $l->actor_id, 'ip_address' => $l->ip_address,
            'status' => $l->status, 'failure_reason' => $l->failure_reason, 'contains_pii' => $l->contains_pii, 'data_exported' => $l->data_exported,
        ]);

        if ($format === 'json') {
            file_put_contents($path, $data->toJson(JSON_PRETTY_PRINT));
        } elseif ($format === 'csv') {
            $handle = fopen($path, 'w');
            if ($data->isNotEmpty()) {
                fputcsv($handle, array_keys($data->first()));
                foreach ($data as $row) fputcsv($handle, $row);
            }
            fclose($handle);
        } else {
            $this->error("Unsupported format: {$format}");
            return self::FAILURE;
        }

        $this->info("Exported to: {$path} ({$logs->count()} records)");
        return self::SUCCESS;
    }

    private function parsePeriod(string $period): Carbon
    {
        return match(true) {
            str_ends_with($period, 'h') => now()->subHours((int)rtrim($period, 'h')),
            str_ends_with($period, 'd') => now()->subDays((int)rtrim($period, 'd')),
            str_ends_with($period, 'w') => now()->subWeeks((int)rtrim($period, 'w')),
            str_ends_with($period, 'm') => now()->subMonths((int)rtrim($period, 'm')),
            default => now()->subHours(24),
        };
    }
}