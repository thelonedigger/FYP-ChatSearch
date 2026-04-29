<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DataRetentionPolicy;
use App\Services\Audit\DataRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(private DataRetentionService $retentionService) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => 'sometimes|string', 'entity_id' => 'sometimes|integer', 'action' => 'sometimes|string',
            'category' => 'sometimes|string', 'actor_id' => 'sometimes|integer', 'status' => 'sometimes|string|in:success,failure,denied',
            'from' => 'sometimes|date', 'to' => 'sometimes|date', 'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = AuditLog::query()->orderByDesc('performed_at');
        
        foreach (['entity_type', 'entity_id', 'actor_id', 'status'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->input($field));
        }
        if ($request->filled('action')) $query->where('action', 'like', "%{$request->input('action')}%");
        if ($request->filled('category')) $query->inCategory($request->input('category'));
        if ($request->filled('from')) $query->where('performed_at', '>=', $request->input('from'));
        if ($request->filled('to')) $query->where('performed_at', '<=', $request->input('to'));

        return response()->json($query->paginate($request->input('limit', 50)));
    }

    public function forEntity(Request $request, string $entityType, int $entityId): JsonResponse
    {
        return response()->json(AuditLog::forEntity($entityType, $entityId)->orderByDesc('performed_at')->paginate(50));
    }

    public function summary(Request $request): JsonResponse
    {
        $request->validate(['period' => 'sometimes|string|in:1h,6h,12h,24h,7d,30d']);
        $since = $this->parsePeriod($request->input('period', '24h'));

        $stats = AuditLog::where('performed_at', '>=', $since)->selectRaw("
            COUNT(*) as total, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
            SUM(CASE WHEN status = 'failure' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN contains_pii = true THEN 1 ELSE 0 END) as with_pii,
            SUM(CASE WHEN data_exported = true THEN 1 ELSE 0 END) as exports
        ")->first();

        return response()->json([
            'period' => $request->input('period', '24h'), 'since' => $since->toISOString(),
            'totals' => ['total' => (int)$stats->total, 'successful' => (int)$stats->successful, 'failed' => (int)$stats->failed, 'with_pii' => (int)$stats->with_pii, 'exports' => (int)$stats->exports],
            'by_category' => AuditLog::where('performed_at', '>=', $since)->selectRaw('action_category, COUNT(*) as count')->groupBy('action_category')->pluck('count', 'action_category'),
            'by_entity_type' => AuditLog::where('performed_at', '>=', $since)->whereNotNull('entity_type')->selectRaw('entity_type, COUNT(*) as count')->groupBy('entity_type')->pluck('count', 'entity_type'),
            'top_actions' => AuditLog::where('performed_at', '>=', $since)->selectRaw('action, COUNT(*) as count')->groupBy('action')->orderByDesc('count')->limit(10)->pluck('count', 'action'),
            'generated_at' => now()->toISOString(),
        ]);
    }

    public function retentionPolicies(): JsonResponse
    {
        $policies = DataRetentionPolicy::orderBy('entity_type')->orderByDesc('priority')->get();
        return response()->json(['policies' => $policies, 'total' => $policies->count()]);
    }

    public function retentionStatus(): JsonResponse
    {
        return response()->json(['status' => $this->retentionService->getRetentionStatus(), 'generated_at' => now()->toISOString()]);
    }

    public function createRetentionPolicy(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|unique:data_retention_policies,name', 'entity_type' => 'required|string',
            'description' => 'sometimes|string', 'retention_days' => 'required|integer|min:1',
            'retention_action' => 'required|string|in:delete,anonymize,archive', 'conditions' => 'sometimes|array',
            'is_active' => 'sometimes|boolean', 'priority' => 'sometimes|integer',
            'legal_basis' => 'sometimes|string', 'compliance_framework' => 'sometimes|string',
        ]);

        $policy = DataRetentionPolicy::create($request->only([
            'name', 'entity_type', 'description', 'retention_days', 'retention_action',
            'conditions', 'is_active', 'priority', 'legal_basis', 'compliance_framework',
        ]));

        return response()->json(['message' => 'Retention policy created', 'policy' => $policy], 201);
    }

    public function updateRetentionPolicy(Request $request, DataRetentionPolicy $policy): JsonResponse
    {
        $request->validate([
            'name' => "sometimes|string|unique:data_retention_policies,name,{$policy->id}", 'description' => 'sometimes|string',
            'retention_days' => 'sometimes|integer|min:1', 'retention_action' => 'sometimes|string|in:delete,anonymize,archive',
            'conditions' => 'sometimes|array', 'is_active' => 'sometimes|boolean', 'priority' => 'sometimes|integer',
            'legal_basis' => 'sometimes|string', 'compliance_framework' => 'sometimes|string',
        ]);

        $policy->update($request->only(['name', 'description', 'retention_days', 'retention_action', 'conditions', 'is_active', 'priority', 'legal_basis', 'compliance_framework']));
        return response()->json(['message' => 'Retention policy updated', 'policy' => $policy->fresh()]);
    }

    private function parsePeriod(string $period): \Carbon\Carbon
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