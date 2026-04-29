<?php

namespace App\Services\Analytics;

use App\Models\SearchMetric;
use App\Models\ResultInteraction;
use App\Models\SystemMetric;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class MetricsService
{
    public function recordSearchMetric(array $d): SearchMetric
    {
        return SearchMetric::create([
            'session_id' => $d['session_id'], 'user_id' => $d['user_id'] ?? null,
            'query' => $d['query'], 'query_hash' => SearchMetric::hashQuery($d['query']),
            'intent' => $d['intent'] ?? null, 'search_type' => $d['search_type'] ?? 'hybrid', 'results_count' => $d['results_count'] ?? 0,
            'vector_time_ms' => $d['vector_time_ms'] ?? null, 'trigram_time_ms' => $d['trigram_time_ms'] ?? null,
            'fusion_time_ms' => $d['fusion_time_ms'] ?? null, 'rerank_time_ms' => $d['rerank_time_ms'] ?? null,
            'llm_time_ms' => $d['llm_time_ms'] ?? null, 'intent_classification_time_ms' => $d['intent_classification_time_ms'] ?? null,
            'total_time_ms' => $d['total_time_ms'], 'top_relevance_score' => $d['top_relevance_score'] ?? null,
            'avg_relevance_score' => $d['avg_relevance_score'] ?? null,
        ]);
    }

    public function recordResultInteraction(array $d): ResultInteraction
    {
        return ResultInteraction::create([
            'session_id' => $d['session_id'], 'user_id' => $d['user_id'] ?? null,
            'search_metric_id' => $d['search_metric_id'],
            'text_chunk_id' => $d['text_chunk_id'] ?? null, 'document_id' => $d['document_id'] ?? null,
            'interaction_type' => $d['interaction_type'] ?? 'click', 'result_position' => $d['result_position'],
            'relevance_score' => $d['relevance_score'] ?? null,
        ]);
    }

    public function recordSystemMetric(string $type, string $name, float $value, ?string $unit = null, array $meta = []): void
    {
        SystemMetric::create(['metric_type' => $type, 'metric_name' => $name, 'value' => $value, 'unit' => $unit, 'metadata' => $meta ?: null]);
    }

    public function getSearchPerformanceStats(string $period = '24h', ?int $userId = null): array
    {
        $since = $this->parsePeriod($period);
        $base = $this->scopedSearchMetrics($since, $userId);

        $s = (clone $base)->selectRaw('
            COUNT(*) as total, AVG(total_time_ms) as avg_total, AVG(vector_time_ms) as avg_vector,
            AVG(trigram_time_ms) as avg_trigram, AVG(fusion_time_ms) as avg_fusion, AVG(rerank_time_ms) as avg_rerank,
            AVG(llm_time_ms) as avg_llm, AVG(intent_classification_time_ms) as avg_intent, AVG(results_count) as avg_results,
            AVG(top_relevance_score) as avg_rel, MIN(total_time_ms) as min_t, MAX(total_time_ms) as max_t,
            PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY total_time_ms) as p50,
            PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY total_time_ms) as p95,
            PERCENTILE_CONT(0.99) WITHIN GROUP (ORDER BY total_time_ms) as p99
        ')->first();

        $byType = (clone $base)
            ->selectRaw('search_type, COUNT(*) as count, AVG(total_time_ms) as avg_time, AVG(results_count) as avg_results')
            ->groupBy('search_type')->get()->keyBy('search_type');

        return [
            'period' => $period, 'since' => $since->toISOString(), 'total_searches' => (int)($s->total ?? 0),
            'timing' => [
                'avg_total_ms' => round($s->avg_total ?? 0, 2), 'avg_vector_ms' => round($s->avg_vector ?? 0, 2),
                'avg_trigram_ms' => round($s->avg_trigram ?? 0, 2), 'avg_fusion_ms' => round($s->avg_fusion ?? 0, 2),
                'avg_rerank_ms' => round($s->avg_rerank ?? 0, 2), 'avg_llm_ms' => round($s->avg_llm ?? 0, 2),
                'avg_intent_classification_ms' => round($s->avg_intent ?? 0, 2), 'min_ms' => round($s->min_t ?? 0, 2),
                'max_ms' => round($s->max_t ?? 0, 2), 'p50_ms' => round($s->p50 ?? 0, 2),
                'p95_ms' => round($s->p95 ?? 0, 2), 'p99_ms' => round($s->p99 ?? 0, 2),
            ],
            'quality' => ['avg_results_count' => round($s->avg_results ?? 0, 1), 'avg_top_relevance' => round($s->avg_rel ?? 0, 4)],
            'by_search_type' => $byType->map(fn($i) => ['count' => (int)$i->count, 'avg_time_ms' => round($i->avg_time, 2), 'avg_results' => round($i->avg_results, 1)])->toArray(),
        ];
    }

    public function getQueryPopularity(string $period = '7d', int $limit = 20, ?int $userId = null): array
    {
        $q = $this->scopedSearchMetrics($this->parsePeriod($period), $userId)
            ->selectRaw('query_hash, MIN(query) as query, COUNT(*) as count, AVG(results_count) as avg_results, AVG(total_time_ms) as avg_time')
            ->groupBy('query_hash')->orderByDesc('count')->limit($limit)->get();

        return ['period' => $period, 'queries' => $q->map(fn($i) => [
            'query' => $i->query, 'count' => (int)$i->count, 'avg_results' => round($i->avg_results, 1), 'avg_time_ms' => round($i->avg_time, 2)
        ])->toArray()];
    }

    public function getResponseTimeHistogram(string $period = '24h', int $buckets = 20, ?int $userId = null): array
    {
        $since = $this->parsePeriod($period);
        $base = $this->scopedSearchMetrics($since, $userId);

        $r = (clone $base)->selectRaw('MIN(total_time_ms) as min, MAX(total_time_ms) as max')->first();
        if (!$r->min || !$r->max) return ['period' => $period, 'buckets' => [], 'total' => 0];

        $size = max(1, ceil((ceil($r->max) - floor($r->min)) / $buckets));
        $hist = (clone $base)
            ->selectRaw("FLOOR(total_time_ms / ?) * ? as bucket, COUNT(*) as count", [$size, $size])
            ->groupBy('bucket')->orderBy('bucket')->get();
        $total = $hist->sum('count');

        return ['period' => $period, 'bucket_size_ms' => $size, 'total' => $total, 'buckets' => $hist->map(fn($i) => [
            'range_start' => (int)$i->bucket, 'range_end' => (int)$i->bucket + $size,
            'count' => (int)$i->count, 'percentage' => $total > 0 ? round(($i->count / $total) * 100, 2) : 0,
        ])->toArray()];
    }

    public function getIntentDistribution(string $period = '24h', ?int $userId = null): array
    {
        $dist = $this->scopedSearchMetrics($this->parsePeriod($period), $userId)->whereNotNull('intent')
            ->selectRaw('intent, COUNT(*) as count, AVG(total_time_ms) as avg_time, AVG(results_count) as avg_results')
            ->groupBy('intent')->orderByDesc('count')->get();
        $total = $dist->sum('count');

        return ['period' => $period, 'total' => $total, 'intents' => $dist->map(fn($i) => [
            'intent' => $i->intent, 'count' => (int)$i->count, 'percentage' => $total > 0 ? round(($i->count / $total) * 100, 2) : 0,
            'avg_time_ms' => round($i->avg_time, 2), 'avg_results' => round($i->avg_results, 1),
        ])->toArray()];
    }

    public function getTimeSeries(string $period = '24h', string $interval = 'hour', ?int $userId = null): array
    {
        $fmt = match($interval) { 'minute' => 'minute', 'day' => 'day', default => 'hour' };
        $series = $this->scopedSearchMetrics($this->parsePeriod($period), $userId)
            ->selectRaw("DATE_TRUNC(?, created_at) as bucket, COUNT(*) as count, AVG(total_time_ms) as avg_time, AVG(results_count) as avg_results", [$fmt])
            ->groupBy('bucket')->orderBy('bucket')->get();

        return ['period' => $period, 'interval' => $interval, 'data' => $series->map(fn($i) => [
            'timestamp' => Carbon::parse($i->bucket)->toISOString(),
            'search_count' => (int)$i->count, 'avg_time_ms' => round($i->avg_time, 2), 'avg_results' => round($i->avg_results, 1),
        ])->toArray()];
    }

    public function getInteractionStats(string $period = '24h', ?int $userId = null): array
    {
        $since = $this->parsePeriod($period);
        $total = $this->scopedSearchMetrics($since, $userId)->count();

        $intBase = ResultInteraction::where('created_at', '>=', $since);
        if ($userId) $intBase->where('user_id', $userId);

        $int = (clone $intBase)
            ->selectRaw('interaction_type, COUNT(*) as count, AVG(result_position) as avg_pos, AVG(relevance_score) as avg_rel')
            ->groupBy('interaction_type')->get()->keyBy('interaction_type');

        $pos = (clone $intBase)->where('interaction_type', 'click')
            ->selectRaw('result_position, COUNT(*) as count')->groupBy('result_position')->orderBy('result_position')->limit(10)->get();

        $clicks = $int->get('click')?->count ?? 0;

        return [
            'period' => $period, 'total_searches' => $total, 'total_clicks' => $clicks,
            'click_through_rate' => $total > 0 ? round(($clicks / $total) * 100, 2) : 0,
            'by_interaction_type' => $int->map(fn($i) => ['count' => (int)$i->count, 'avg_position' => round($i->avg_pos, 2), 'avg_relevance' => round($i->avg_rel ?? 0, 4)])->toArray(),
            'clicks_by_position' => $pos->map(fn($i) => ['position' => (int)$i->result_position, 'count' => (int)$i->count])->toArray(),
        ];
    }

    /**
     * Build a base query for search_metrics scoped by time and optionally by user.
     */
    private function scopedSearchMetrics(Carbon $since, ?int $userId = null): Builder
    {
        $query = SearchMetric::where('created_at', '>=', $since);
        if ($userId) {
            $query->where('user_id', $userId);
        }
        return $query;
    }

    private function parsePeriod(string $p): Carbon
    {
        return match(true) {
            str_ends_with($p, 'h') => now()->subHours((int)rtrim($p, 'h')),
            str_ends_with($p, 'd') => now()->subDays((int)rtrim($p, 'd')),
            str_ends_with($p, 'w') => now()->subWeeks((int)rtrim($p, 'w')),
            str_ends_with($p, 'm') => now()->subMonths((int)rtrim($p, 'm')),
            default => now()->subHours(24),
        };
    }
}