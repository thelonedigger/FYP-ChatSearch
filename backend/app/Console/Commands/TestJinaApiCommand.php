<?php

namespace App\Console\Commands;

use App\Services\Documents\Processing\EmbeddingService;
use App\Services\Search\DataRetrieval\RerankerService;
use Illuminate\Console\Command;

class TestJinaApiCommand extends Command
{
    protected $signature = 'jina:test {--embeddings} {--reranker} {--all}';
    protected $description = 'Test Jina AI API connectivity';

    public function handle(EmbeddingService $embeddingService, RerankerService $rerankerService): int
    {
        $testAll = $this->option('all') || (!$this->option('embeddings') && !$this->option('reranker'));
        $this->info("Jina AI API Test Suite\n========================\n");
        $success = true;

        if ($testAll || $this->option('embeddings')) {
            $this->info("Testing Embeddings (Model: {$embeddingService->getModel()}, Dims: {$embeddingService->getDimensions()})\n");
            
            try {
                $start = microtime(true);
                $emb = $embeddingService->generateSingleEmbedding('The quick brown fox jumps over the lazy dog.');
                $this->line("   ✅ Single embedding: " . count($emb) . " dims (" . round((microtime(true) - $start) * 1000, 2) . "ms)");
                
                $start = microtime(true);
                $emb = $embeddingService->generateQueryEmbedding('What does the fox do?');
                $this->line("   ✅ Query embedding: " . count($emb) . " dims (" . round((microtime(true) - $start) * 1000, 2) . "ms)");
                
                $start = microtime(true);
                $embs = $embeddingService->generateEmbeddings(['ML is AI subset.', 'Deep learning uses NNs.', 'NLP enables text understanding.']);
                $this->line("   ✅ Batch embeddings: " . count($embs) . " (" . round((microtime(true) - $start) * 1000, 2) . "ms)\n");
            } catch (\Exception $e) {
                $this->error("   ❌ Failed: " . $e->getMessage());
                $success = false;
            }
        }

        if ($testAll || $this->option('reranker')) {
            $this->info("Testing Reranker (Model: {$rerankerService->getModel()}, Enabled: " . ($rerankerService->isEnabled() ? 'Yes' : 'No') . ")\n");
            
            if (!$rerankerService->isEnabled()) {
                $this->warn("   Reranker disabled, skipping.\n");
            } else {
                try {
                    $docs = ['ML automates model building.', 'Weather is sunny.', 'Deep learning is ML subset.', 'I cook Italian.', 'NNs are bio-inspired.'];
                    $start = microtime(true);
                    $results = $rerankerService->rerank('What is machine learning?', $docs, 3);
                    $this->line("   ✅ Reranked 5→3 (" . round((microtime(true) - $start) * 1000, 2) . "ms)\n");
                    $this->table(['Rank', 'Orig Idx', 'Score', 'Preview'], collect($results)->map(fn($r, $i) => [$i + 1, $r['index'], round($r['score'], 4), substr($r['document'], 0, 40) . '...'])->toArray());
                } catch (\Exception $e) {
                    $this->error("   ❌ Failed: " . $e->getMessage());
                    $success = false;
                }
            }
        }

        $this->newLine();
        $success ? $this->info('All tests passed!') : $this->error('Some tests failed.');
        return $success ? self::SUCCESS : self::FAILURE;
    }
}