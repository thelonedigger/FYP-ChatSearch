<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private MetricsService $metrics) {}

    public function searchPerformance(Request $request): JsonResponse
    {
        $request->validate(['period' => 'sometimes|string|in:1h,6h,12h,24h,7d,30d', 'user_id' => 'sometimes|integer|exists:users,id']);
        return response()->json($this->metrics->getSearchPerformanceStats($request->input('period', '24h'), $request->input('user_id')));
    }

    public function queryPopularity(Request $request): JsonResponse
    {
        $request->validate(['period' => 'sometimes|string|in:24h,7d,30d', 'limit' => 'sometimes|integer|min:5|max:100', 'user_id' => 'sometimes|integer|exists:users,id']);
        return response()->json($this->metrics->getQueryPopularity($request->input('period', '7d'), $request->input('limit', 20), $request->input('user_id')));
    }

    public function responseTimeHistogram(Request $request): JsonResponse
    {
        $request->validate(['period' => 'sometimes|string|in:1h,6h,12h,24h,7d', 'buckets' => 'sometimes|integer|min:5|max:50', 'user_id' => 'sometimes|integer|exists:users,id']);
        return response()->json($this->metrics->getResponseTimeHistogram($request->input('period', '24h'), $request->input('buckets', 20), $request->input('user_id')));
    }

    public function intentDistribution(Request $request): JsonResponse
    {
        $request->validate(['period' => 'sometimes|string|in:1h,6h,12h,24h,7d,30d', 'user_id' => 'sometimes|integer|exists:users,id']);
        return response()->json($this->metrics->getIntentDistribution($request->input('period', '24h'), $request->input('user_id')));
    }

    public function timeSeries(Request $request): JsonResponse
    {
        $request->validate(['period' => 'sometimes|string|in:1h,6h,12h,24h,7d,30d', 'interval' => 'sometimes|string|in:minute,hour,day', 'user_id' => 'sometimes|integer|exists:users,id']);
        return response()->json($this->metrics->getTimeSeries($request->input('period', '24h'), $request->input('interval', 'hour'), $request->input('user_id')));
    }

    public function interactionStats(Request $request): JsonResponse
    {
        $request->validate(['period' => 'sometimes|string|in:1h,6h,12h,24h,7d,30d', 'user_id' => 'sometimes|integer|exists:users,id']);
        return response()->json($this->metrics->getInteractionStats($request->input('period', '24h'), $request->input('user_id')));
    }

    public function recordInteraction(Request $request): JsonResponse
    {
        $request->validate([
            'search_metric_id' => 'required|integer|exists:search_metrics,id', 'text_chunk_id' => 'sometimes|integer|exists:text_chunks,id',
            'document_id' => 'sometimes|integer|exists:documents,id', 'interaction_type' => 'sometimes|string|in:click,expand,copy',
            'result_position' => 'required|integer|min:0', 'relevance_score' => 'sometimes|numeric',
        ]);

        $interaction = $this->metrics->recordResultInteraction(array_merge([
            'session_id' => session()->getId(),
            'user_id' => auth()->id(),
        ], $request->only([
            'search_metric_id', 'text_chunk_id', 'document_id', 'interaction_type', 'result_position', 'relevance_score'
        ])));

        return response()->json(['message' => 'Interaction recorded', 'interaction_id' => $interaction->id], 201);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $request->validate([
            'period' => 'sometimes|string|in:1h,6h,12h,24h,7d,30d',
            'user_id' => 'sometimes|integer|exists:users,id',
        ]);
        $p = $request->input('period', '24h');
        $userId = $request->input('user_id');

        return response()->json([
            'performance' => $this->metrics->getSearchPerformanceStats($p, $userId),
            'intent_distribution' => $this->metrics->getIntentDistribution($p, $userId),
            'interactions' => $this->metrics->getInteractionStats($p, $userId),
            'popular_queries' => $this->metrics->getQueryPopularity($p, 10, $userId),
            'time_series' => $this->metrics->getTimeSeries($p, $p === '1h' ? 'minute' : 'hour', $userId),
            'generated_at' => now()->toISOString(),
        ]);
    }
}