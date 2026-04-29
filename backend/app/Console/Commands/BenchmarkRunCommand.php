<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\TextChunk;
use App\Services\Documents\Processing\EmbeddingService;
use App\Services\Search\DataRetrieval\RerankerService;
use App\Services\Search\DataRetrieval\RankFusionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

class BenchmarkRunCommand extends Command
{
    protected $signature = 'benchmark:run
                            {--from=1 : Start query number (1-based, inclusive)}
                            {--to=100 : End query number (1-based, inclusive)}
                            {--delay=2 : Seconds to sleep between queries for rate limiting (supports decimals)}
                            {--limit=100 : Max retrieval candidates per strategy}
                            {--k=10 : Evaluation depth (nDCG@k, P@k, etc.)}
                            {--aggregate : Aggregate all saved result files and display final metrics}';

    protected $description = 'Run BEIR SciFact retrieval benchmark across search strategies';

    private const DATASET_PATH = 'benchmark/scifact';
    private const RESULTS_DIR  = 'benchmark/results';

    private int $k;
    private int $documentId;

    
    private array $corpusIdToChunkId = [];

    
    private array $chunkIdToCorpusId = [];

    public function handle(
        EmbeddingService $embeddingService,
        RankFusionService $rankFusionService,
        RerankerService $rerankerService,
    ): int {
        $this->k = (int) $this->option('k');
        if ($this->option('aggregate')) {
            return $this->aggregateResults();
        }
        $document = Document::where('file_hash', 'beir_scifact_benchmark_corpus')->first();
        if (!$document) {
            $this->error('Benchmark corpus not found. Run `php artisan benchmark:seed` first.');
            return self::FAILURE;
        }
        $this->documentId = $document->id;

        $basePath = storage_path('app/' . self::DATASET_PATH);
        $queries  = $this->loadQueries($basePath . '/queries.jsonl');
        $qrels    = $this->loadQrels($basePath . '/qrels/test.tsv');

        if ($queries->isEmpty() || empty($qrels)) {
            $this->error('Failed to load queries or qrels. Check the dataset files.');
            return self::FAILURE;
        }

        $this->info("Loaded {$queries->count()} queries and " . count($qrels) . " qrel entries.");


        $queries = $queries->filter(fn($q) => isset($qrels[$q['_id']]));
        $this->info("Filtered to {$queries->count()} queries with relevance judgments (test split).");
        $this->buildCorpusMapping();
        $this->info("Mapped " . count($this->corpusIdToChunkId) . " corpus IDs to chunk IDs.");
        $from = max(1, (int) $this->option('from'));
        $to   = min($queries->count(), (int) $this->option('to'));
        $delay = (float) $this->option('delay');
        $limit = (int) $this->option('limit');
        $querySubset = $queries->slice($from - 1, $to - $from + 1)->values();

        $this->info("Running benchmark for queries {$from}–{$to} ({$querySubset->count()} queries).");
        $this->info("Strategy retrieval limit: {$limit} | Eval depth: k={$this->k} | Delay: {$delay}s\n");

        if (!$rerankerService->isEnabled()) {
            $this->warn("Reranker is disabled — hybrid+rerank results will fall back to hybrid.\n");
        }
        $results    = [];
        $bar        = $this->output->createProgressBar($querySubset->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        $vectorThreshold  = 0.0;  // No threshold filtering for benchmark — we want full recall
        $trigramThreshold = 0.0;

        foreach ($querySubset as $idx => $query) {
            $queryId   = $query['_id'];
            $queryText = $query['text'];
            $relevant  = $qrels[$queryId] ?? [];

            $bar->setMessage("Query {$queryId}");

            $queryResult = [
                'query_id'       => $queryId,
                'query_text'     => $queryText,
                'num_relevant'   => count($relevant),
                'strategies'     => [],
            ];
            $embStart       = microtime(true);
            $queryEmbedding = $embeddingService->generateQueryEmbedding($queryText);
            $embTimeMs      = round((microtime(true) - $embStart) * 1000, 2);
            $embeddingJson  = json_encode($queryEmbedding);

            if (empty($queryEmbedding)) {
                $this->newLine();
                $this->warn("  Empty embedding for query {$queryId}, skipping.");
                $bar->advance();
                continue;
            }
            $start         = microtime(true);
            $vectorResults = $this->vectorSearch($embeddingJson, $vectorThreshold, $limit);
            $vectorTimeMs  = round((microtime(true) - $start) * 1000, 2) + $embTimeMs;

            $queryResult['strategies']['vector'] = $this->evaluateStrategy(
                'vector', $vectorResults, $relevant, $vectorTimeMs
            );
            $start          = microtime(true);
            $trigramResults = $this->trigramSearch($queryText, $trigramThreshold, $limit);
            $trigramTimeMs  = round((microtime(true) - $start) * 1000, 2);

            $queryResult['strategies']['trigram'] = $this->evaluateStrategy(
                'trigram', $trigramResults, $relevant, $trigramTimeMs
            );
            $start  = microtime(true);
            $fused  = $rankFusionService->fuseRankings(
                [$vectorResults, $trigramResults],
                [config('text_processing.search.vector_weight', 0.7),
                 config('text_processing.search.trigram_weight', 0.3)]
            )->take($limit);
            $hybridTimeMs = $vectorTimeMs + $trigramTimeMs + round((microtime(true) - $start) * 1000, 2);

            $queryResult['strategies']['hybrid'] = $this->evaluateStrategy(
                'hybrid', $fused, $relevant, $hybridTimeMs
            );
            $rerankTimeMs = 0;
            if ($rerankerService->isEnabled() && $fused->isNotEmpty()) {
                $start    = microtime(true);
                try {
                    $reranked = $rerankerService->rerankChunks($queryText, $fused);
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->warn("  Reranking failed for query {$queryId}: {$e->getMessage()}");
                    $reranked = $fused;
                }
                $rerankTimeMs = round((microtime(true) - $start) * 1000, 2);
            } else {
                $reranked = $fused;
            }

            $queryResult['strategies']['hybrid_rerank'] = $this->evaluateStrategy(
                'hybrid_rerank', $reranked, $relevant, $hybridTimeMs + $rerankTimeMs
            );

            $results[] = $queryResult;
            $bar->advance();
            if ($idx < $querySubset->count() - 1 && $delay > 0) {
                usleep((int) ($delay * 1_000_000));
            }
        }

        $bar->setMessage('Done!');
        $bar->finish();
        $this->newLine(2);
        $this->saveResults($results, $from, $to);
        $this->displayBatchSummary($results);

        return self::SUCCESS;
    }

    private function vectorSearch(string $embeddingJson, float $threshold, int $limit): Collection
    {
        return TextChunk::select('text_chunks.*')
            ->selectRaw('1 - (embedding <=> ?) as similarity', [$embeddingJson])
            ->where('document_id', $this->documentId)
            ->whereRaw('1 - (embedding <=> ?) >= ?', [$embeddingJson, $threshold])
            ->orderByRaw('1 - (embedding <=> ?) DESC', [$embeddingJson])
            ->limit($limit)
            ->get()
            ->each(function ($chunk) {
                $chunk->search_strategy = 'vector';
                $chunk->relevance_score = $chunk->similarity;
            });
    }

    private function trigramSearch(string $query, float $threshold, int $limit): Collection
    {
        return TextChunk::select('text_chunks.*')
            ->selectRaw("ts_rank_cd(to_tsvector('english', content), plainto_tsquery('english', ?)) as fts_score", [$query])
            ->where('document_id', $this->documentId)
            ->whereRaw("to_tsvector('english', content) @@ plainto_tsquery('english', ?)", [$query])
            ->orderByRaw("ts_rank_cd(to_tsvector('english', content), plainto_tsquery('english', ?)) DESC", [$query])
            ->limit($limit)
            ->get()
            ->each(function ($chunk) {
                $chunk->search_strategy = 'fts';
                $chunk->relevance_score = $chunk->fts_score;
            });
    }

    /**
     * Evaluate a ranked result list against the ground truth relevance judgments.
     *
     * @param  string      $strategy  Strategy label
     * @param  Collection  $results   Ranked TextChunk results
     * @param  array       $relevant  Map of beir_corpus_id → relevance score
     * @param  float       $timeMs    Total latency for this strategy
     */
    private function evaluateStrategy(string $strategy, Collection $results, array $relevant, float $timeMs): array
    {
        $k       = $this->k;
        $topK    = $results->take($k)->values();
        $ranked  = [];

        foreach ($topK as $rank => $chunk) {
            $corpusId = $this->chunkIdToCorpusId[$chunk->id] ?? null;
            $rel      = ($corpusId !== null && isset($relevant[$corpusId])) ? (int) $relevant[$corpusId] : 0;
            $ranked[] = [
                'rank'           => $rank + 1,
                'chunk_id'       => $chunk->id,
                'beir_corpus_id' => $corpusId,
                'relevance'      => $rel,
                'score'          => round($chunk->relevance_score ?? $chunk->fusion_score ?? 0, 6),
            ];
        }

        $relValues = array_column($ranked, 'relevance');

        return [
            'strategy'     => $strategy,
            'ndcg'         => $this->computeNdcg($relValues, $relevant, $k),
            'mrr'          => $this->computeMrr($relValues),
            'precision'    => $this->computePrecision($relValues, $k),
            'recall'       => $this->computeRecall($relValues, count($relevant)),
            'results_count' => $results->count(),
            'time_ms'      => $timeMs,
            'top_results'  => $ranked,
        ];
    }

    /**
     * Normalized Discounted Cumulative Gain at k.
     */
    private function computeNdcg(array $relevances, array $allRelevant, int $k): float
    {
        $dcg = 0.0;
        for ($i = 0; $i < min($k, count($relevances)); $i++) {
            $dcg += $relevances[$i] / log($i + 2, 2);  // log2(rank+1), rank is 1-based
        }
        $idealRels = array_values($allRelevant);
        rsort($idealRels);
        $idcg = 0.0;
        for ($i = 0; $i < min($k, count($idealRels)); $i++) {
            $idcg += $idealRels[$i] / log($i + 2, 2);
        }

        return $idcg > 0 ? round($dcg / $idcg, 4) : 0.0;
    }

    /**
     * Mean Reciprocal Rank (reciprocal rank of the first relevant result).
     */
    private function computeMrr(array $relevances): float
    {
        foreach ($relevances as $i => $rel) {
            if ($rel > 0) {
                return round(1 / ($i + 1), 4);
            }
        }
        return 0.0;
    }

    /**
     * Precision at k.
     */
    private function computePrecision(array $relevances, int $k): float
    {
        $hits = 0;
        for ($i = 0; $i < min($k, count($relevances)); $i++) {
            if ($relevances[$i] > 0) {
                $hits++;
            }
        }
        return round($hits / $k, 4);
    }

    /**
     * Recall at k.
     */
    private function computeRecall(array $relevances, int $totalRelevant): float
    {
        if ($totalRelevant === 0) {
            return 0.0;
        }

        $hits = 0;
        foreach ($relevances as $rel) {
            if ($rel > 0) {
                $hits++;
            }
        }

        return round($hits / $totalRelevant, 4);
    }

    private function buildCorpusMapping(): void
    {
        TextChunk::where('document_id', $this->documentId)
            ->select(['id', 'metadata'])
            ->chunkById(500, function ($chunks) {
                foreach ($chunks as $chunk) {
                    $meta     = $chunk->metadata ?? [];
                    $corpusId = $meta['beir_corpus_id'] ?? null;
                    if ($corpusId !== null) {
                        $this->corpusIdToChunkId[$corpusId] = $chunk->id;
                        $this->chunkIdToCorpusId[$chunk->id] = (string) $corpusId;
                    }
                }
            });
    }

    private function loadQueries(string $path): BaseCollection
    {
        if (!file_exists($path)) {
            return collect();
        }

        $queries = collect();
        $handle  = fopen($path, 'r');
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode(trim($line), true);
            if ($entry && isset($entry['_id'])) {
                $queries->push($entry);
            }
        }
        fclose($handle);

        return $queries;
    }

    /**
     * Load qrels into the format: [query_id => [corpus_id => relevance_score, ...], ...]
     */
    private function loadQrels(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $qrels  = [];
        $handle = fopen($path, 'r');
        $firstLine = fgets($handle);
        if ($firstLine !== false && !str_starts_with(trim($firstLine), 'query-id')) {
            rewind($handle);
        }

        while (($line = fgets($handle)) !== false) {
            $parts = preg_split('/\t/', trim($line));
            if (count($parts) >= 3) {
                $queryId  = $parts[0];
                $corpusId = $parts[1];
                $score    = (int) $parts[2];
                if ($score > 0) {
                    $qrels[$queryId][$corpusId] = $score;
                }
            }
        }
        fclose($handle);

        return $qrels;
    }

    private function saveResults(array $results, int $from, int $to): void
    {
        $dir = storage_path('app/' . self::RESULTS_DIR);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = "run_{$from}_{$to}.json";
        $filepath = $dir . '/' . $filename;

        file_put_contents($filepath, json_encode([
            'meta' => [
                'from'       => $from,
                'to'         => $to,
                'k'          => $this->k,
                'timestamp'  => now()->toISOString(),
                'query_count' => count($results),
            ],
            'results' => $results,
        ], JSON_PRETTY_PRINT));

        $this->info("Results saved to: {$filepath}");
    }

    private function displayBatchSummary(array $results): void
    {
        $strategies = ['vector', 'trigram', 'hybrid', 'hybrid_rerank'];
        $metrics    = [];

        foreach ($strategies as $strategy) {
            $stratResults = collect($results)
                ->map(fn($r) => $r['strategies'][$strategy] ?? null)
                ->filter();

            $metrics[] = [
                'Strategy'     => $strategy,
                'nDCG@' . $this->k => $stratResults->avg('ndcg') !== null
                    ? round($stratResults->avg('ndcg'), 4) : '-',
                'MRR'          => round($stratResults->avg('mrr'), 4),
                'P@' . $this->k => round($stratResults->avg('precision'), 4),
                'Recall@' . $this->k => round($stratResults->avg('recall'), 4),
                'Avg Time (ms)' => round($stratResults->avg('time_ms'), 1),
                'Queries'      => $stratResults->count(),
            ];
        }

        $this->table(
            array_keys($metrics[0]),
            $metrics
        );
    }

    private function aggregateResults(): int
    {
        $dir = storage_path('app/' . self::RESULTS_DIR);
        if (!is_dir($dir)) {
            $this->error("No results directory found at: {$dir}");
            return self::FAILURE;
        }

        $files = glob($dir . '/run_*.json');
        if (empty($files)) {
            $this->error('No result files found. Run the benchmark first.');
            return self::FAILURE;
        }

        $this->info("Aggregating " . count($files) . " result file(s)...\n");

        $allResults = [];
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (isset($data['results'])) {
                $allResults = array_merge($allResults, $data['results']);
                $this->line("  Loaded: " . basename($file) . " ({$data['meta']['query_count']} queries)");
            }
        }

        $this->info("\nTotal queries: " . count($allResults));
        $this->newLine();
        $this->displayBatchSummary($allResults);
        $aggrPath = $dir . '/aggregate.json';
        $strategies = ['vector', 'trigram', 'hybrid', 'hybrid_rerank'];
        $aggregate  = [];

        foreach ($strategies as $strategy) {
            $stratResults = collect($allResults)
                ->map(fn($r) => $r['strategies'][$strategy] ?? null)
                ->filter();

            $aggregate[$strategy] = [
                'ndcg'      => round($stratResults->avg('ndcg'), 4),
                'mrr'       => round($stratResults->avg('mrr'), 4),
                'precision' => round($stratResults->avg('precision'), 4),
                'recall'    => round($stratResults->avg('recall'), 4),
                'avg_time_ms' => round($stratResults->avg('time_ms'), 1),
                'query_count' => $stratResults->count(),
            ];
        }

        file_put_contents($aggrPath, json_encode([
            'generated_at'  => now()->toISOString(),
            'total_queries' => count($allResults),
            'k'             => $this->k,
            'strategies'    => $aggregate,
        ], JSON_PRETTY_PRINT));

        $this->info("\nAggregate results saved to: {$aggrPath}");

        return self::SUCCESS;
    }
}