<?php

namespace App\Services\Search\DialogueManagement;

use App\Services\Search\NaturalLanguageUnderstanding\NluService;
use App\Services\Search\DataRetrieval\RetrievalService;
use App\Services\Search\NaturalLanguageGeneration\NlgService;
use App\Services\Analytics\MetricsService;
use App\Services\Analytics\DTOs\SearchTimingData;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;
use GuzzleHttp\Promise\PromiseInterface;
use App\Services\Documents\Processing\EmbeddingService;
use App\Models\Document;

class DialogueManagementService
{
    const SESSION_KEY = 'conversational_search_state';
    const MAX_HISTORY_LENGTH = 10;
    
    public function __construct(
        private NluService $nluService,
        private RetrievalService $retrievalService,
        private NlgService $nlgService,
        private MetricsService $metricsService,
        private AuditService $auditService,
        private EmbeddingService $embeddingService,
    ) {}
    
    public function processQuery(string $query, array $options = [], ?callable $onStatus = null): array
    {
        $timingData = new SearchTimingData();
        $state = $this->getConversationState();

        Log::debug('[DialogueMgmt] processQuery started', [
            'query'                => $query,
            'state_turn'           => $state['turn'] ?? 'NOT SET',
            'has_last_results'     => !empty($state['last_results']),
            'query_history_count'  => count($state['query_history'] ?? []),
            'query_history'        => $state['query_history'] ?? [],
            'session_id'           => Session::getId(),
        ]);
        $onStatus && $onStatus('Classifying Intent');

        $embeddingStart = microtime(true);
        $embeddingPromise = $this->embeddingService->generateQueryEmbeddingAsync($query);
        $intentStart = microtime(true);
        $result = $this->nluService->classifyIntent(
            $query,
            !empty($state['last_results']),
            $state['query_history'] ?? [],
        );
        $intent = $result['intent'];
        $entities = $result['entities'] ?? [];
        $metadata = $result['metadata'] ?? [];
        $timingData->intentClassificationTimeMs = round((microtime(true) - $intentStart) * 1000, 2);
        $precomputedEmbedding = null;
        if ($this->intentNeedsEmbedding($intent)) {
            try {
                $waitStart = microtime(true);
                $precomputedEmbedding = $embeddingPromise->wait();
                $timingData->embeddingTimeMs = round((microtime(true) - $waitStart) * 1000, 2);
            } catch (\Exception $e) {
                Log::warning('Async embedding failed, will fall back to synchronous', ['error' => $e->getMessage()]);
            }
        } else {
            $embeddingPromise->cancel();
        }

        Log::debug('[DialogueMgmt] Intent classified', [
            'query'              => $query,
            'intent'             => $intent,
            'has_context'        => !empty($state['last_results']),
            'history_count_sent' => count($state['query_history'] ?? []),
            'embedding_precomputed' => $precomputedEmbedding !== null,
        ]);

        $deferSummary = $options['defer_summary'] ?? false;
        
        $response = match($intent) {
            NluService::INTENT_NEW_SEARCH => $this->handleNewSearch($query, $options, $state, $timingData, $onStatus, $precomputedEmbedding),
            NluService::INTENT_REFINE_SEARCH => $this->handleRefineSearch($query, $options, $state, $entities),
            NluService::INTENT_GET_MORE_RESULTS => $this->handleMoreResults($query, $options, $state),
            NluService::INTENT_SUMMARIZE_RESULT => $this->handleSummarizeResult($query, $state, $timingData, $deferSummary, $entities),
            NluService::INTENT_CLARIFY_SEARCH => $this->handleClarifySearch($query, $state),
            NluService::INTENT_FIND_DOCUMENT => $this->handleFindDocument($query, $options, $state, $entities),
            default => $this->handleNewSearch($query, $options, $state, $timingData, $onStatus, $precomputedEmbedding)
        };
        
        $timingData->finalize();
        
        if (!empty($metadata)) {
            $response['intent_classification_metadata'] = $metadata;
        }
        $response['timing'] = $timingData->toArray();
        
        $this->updateConversationState($query, $response, $intent);
        $this->recordSearchMetrics($query, $intent, $response, $timingData);
        $this->auditSearchOperation($query, $intent, $response, $timingData);
        
        return $response;
    }

    private function intentNeedsEmbedding(string $intent): bool
    {
        return in_array($intent, [
            NluService::INTENT_NEW_SEARCH,
            NluService::INTENT_REFINE_SEARCH,
            NluService::INTENT_GET_MORE_RESULTS,
        ], true);
    }

    private function auditSearchOperation(string $query, string $intent, array $response, SearchTimingData $timingData): void
    {
        $results = $response['resources'] ?? $response['documents'] ?? collect();
        $resultsCount = is_countable($results) ? count($results) : $results->count();
        $accessedDocumentIds = [];
        if ($response['type'] === 'document_search_results' && isset($response['documents'])) {
            $docs = is_array($response['documents']) ? $response['documents'] : $response['documents']->toArray();
            $accessedDocumentIds = array_column($docs, 'id');
        } elseif (isset($response['resources'])) {
            $resources = is_array($response['resources']) ? $response['resources'] : $response['resources']->toArray();
            $accessedDocumentIds = array_unique(array_column(
                array_column($resources, 'document'),
                'id'
            ));
        }

        $this->auditService->logSearch($query, $resultsCount, [
            'intent' => $intent,
            'search_type' => $response['search_type'] ?? 'conversational',
            'response_type' => $response['type'] ?? 'unknown',
            'conversation_turn' => $response['conversation_turn'] ?? 1,
            'total_time_ms' => $timingData->totalTimeMs,
            'accessed_document_ids' => $accessedDocumentIds,
            'has_summary' => isset($response['summary']),
            'is_refinement' => $response['is_refinement'] ?? false,
        ]);
    }
    
    private function recordSearchMetrics(string $query, string $intent, array $response, SearchTimingData $timingData): void
    {
        try {
            $results = $response['resources'] ?? $response['documents'] ?? collect();
            $resultsCount = is_countable($results) ? count($results) : $results->count();
            
            $relevanceScores = collect($results)->map(fn($item) => 
                $item->relevance_score ?? $item->fusion_score ?? $item->similarity ?? $item['relevance_score'] ?? null
            )->filter()->values();
            
            $this->metricsService->recordSearchMetric([
                'session_id' => Session::getId(),
                'user_id' => auth()->id(),
                'query' => $query,
                'intent' => $intent,
                'search_type' => $response['search_type'] ?? 'hybrid',
                'results_count' => $resultsCount,
                'vector_time_ms' => $timingData->vectorTimeMs,
                'trigram_time_ms' => $timingData->trigramTimeMs,
                'fusion_time_ms' => $timingData->fusionTimeMs,
                'rerank_time_ms' => $timingData->rerankTimeMs,
                'llm_time_ms' => $timingData->llmTimeMs,
                'intent_classification_time_ms' => $timingData->intentClassificationTimeMs,
                'total_time_ms' => $timingData->totalTimeMs,
                'top_relevance_score' => $relevanceScores->first(),
                'avg_relevance_score' => $relevanceScores->avg(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to record search metrics', ['error' => $e->getMessage()]);
        }
    }

    private function timedHybridSearch(string $query, int $limit, ?SearchTimingData $timingData, ?callable $onStatus = null, ?array $precomputedEmbedding = null): Collection
    {
        $onStatus && $onStatus('Searching');

        $vectorStart = microtime(true);

        if (!empty($precomputedEmbedding)) {
            $embeddingJson = json_encode($precomputedEmbedding);
            $vectorResults = $this->retrievalService->searchByEmbedding($query, $embeddingJson, $limit, false);
        } else {
            $vectorResults = $this->retrievalService->searchSimilarChunks($query, $limit, false);
        }

        if ($timingData) {
            $timingData->vectorTimeMs = round((microtime(true) - $vectorStart) * 1000, 2);
        }
        $trigramStart = microtime(true);
        $trigramResults = $this->retrievalService->searchTrigramMatches($query, $limit, false);
        if ($timingData) {
            $timingData->trigramTimeMs = round((microtime(true) - $trigramStart) * 1000, 2);
        }
        $fusionStart = microtime(true);
        $vectorWeight = config('text_processing.search.vector_weight', 0.7);
        $trigramWeight = config('text_processing.search.trigram_weight', 0.3);

        $fusedResults = $this->retrievalService instanceof RetrievalService
            ? app(\App\Services\Search\DataRetrieval\RankFusionService::class)->fuseRankings(
                [$vectorResults, $trigramResults],
                [$vectorWeight, $trigramWeight]
            )->take($limit)
            : collect();

        if ($timingData) {
            $timingData->fusionTimeMs = round((microtime(true) - $fusionStart) * 1000, 2);
        }
        if (!$fusedResults->isEmpty()) {
            $onStatus && $onStatus('Reranking Results');

            $rerankStart = microtime(true);

            $reranker = app(\App\Services\Search\DataRetrieval\RerankerService::class);
            if ($reranker->isEnabled()) {
                try {
                    $fusedResults = $reranker->rerankChunks($query, $fusedResults, $fusedResults->count());
                    if ($timingData) {
                        $timingData->rerankTimeMs = round((microtime(true) - $rerankStart) * 1000, 2);
                    }
                } catch (\Exception $e) {
                    Log::warning('Reranking failed during timed hybrid search', ['error' => $e->getMessage()]);
                }
            }
        }

        return $fusedResults;
    }
    
    private function handleRefineSearch(string $query, array $options, array $state, array $entities = []): array
    {
        $searchType = $options['search_type'] ?? $state['last_search_type'] ?? 'hybrid';
        $limit = $options['limit'] ?? config('text_processing.retrieval.default_limit');

        $documentScope = $entities['document_scope'] ?? null;
        $additionalTerms = $entities['additional_terms'] ?? $query;

        $enhancedQuery = !empty($state['last_query'])
            ? $state['last_query'] . ' ' . $additionalTerms
            : $additionalTerms;
        if ($documentScope) {
            $scopedDocument = Document::whereRaw('similarity(filename, ?) > 0.1', [$documentScope])
                ->orderByRaw('similarity(filename, ?) DESC', [$documentScope])
                ->first();

            if ($scopedDocument) {
                $chunks = $this->retrievalService->hybridSearchInDocument(
                    $scopedDocument->id,
                    $additionalTerms ?: $state['last_query'] ?? $query,
                    $limit,
                );

                return [
                    'type' => 'refined_results',
                    'query' => $query,
                    'enhanced_query' => $additionalTerms,
                    'original_query' => $state['last_query'] ?? '',
                    'search_type' => 'hybrid',
                    'total_results' => $chunks->count(),
                    'resources' => $chunks,
                    'conversation_turn' => ($state['turn'] ?? 0) + 1,
                    'intent' => 'refine_search',
                    'limit' => $limit,
                    'document_scope' => $scopedDocument->filename,
                ];
            }
            $enhancedQuery .= ' ' . $documentScope;
        }

        $chunks = match($searchType) {
            'vector' => $this->retrievalService->searchSimilarChunks($enhancedQuery, $limit),
            'trigram' => $this->retrievalService->searchTrigramMatches($enhancedQuery, $limit),
            default => $this->retrievalService->hybridSearch($enhancedQuery, $limit)
        };
        
        return [
            'type' => 'refined_results',
            'query' => $query,
            'enhanced_query' => $enhancedQuery,
            'original_query' => $state['last_query'] ?? '',
            'search_type' => $searchType,
            'total_results' => $chunks->count(),
            'resources' => $chunks,
            'conversation_turn' => ($state['turn'] ?? 0) + 1,
            'intent' => 'refine_search',
            'limit' => $limit,
        ];
    }

    private function handleFindDocument(string $query, array $options, array $state, array $entities = []): array
    {
        $limit = $options['limit'] ?? config('text_processing.retrieval.default_limit');
        
        if (!empty($state['last_document_results'])) {
            return $this->refineDocumentResults($query, $state, $limit);
        }

        $filename = $entities['filename'] ?? null;

        if ($filename) {
            $documents = $this->retrievalService->searchDocumentsByName($filename, $limit);
        } else {
            $topic = $entities['topic'] ?? $query;
            $documents = $this->searchDocumentsByTopic($query, $topic, $limit);
        }
        
        return [
            'type' => 'document_search_results',
            'query' => $query,
            'total_results' => $documents->count(),
            'documents' => $documents,
            'is_refinement' => false,
            'search_strategy' => $filename ? 'filename' : 'topic',
            'conversation_turn' => ($state['turn'] ?? 0) + 1,
            'intent' => 'find_document',
        ];
    }

    private function searchDocumentsByTopic(string $fullQuery, string $topic, int $limit): \Illuminate\Support\Collection
    {
        $chunks = $this->retrievalService->hybridSearch($fullQuery, $limit * 3);

        if ($chunks->isEmpty()) {
            return $this->retrievalService->searchDocumentsByName($topic, $limit);
        }

        $documents = $chunks
            ->groupBy(fn($chunk) => $chunk->document->id)
            ->map(function ($documentChunks) {
                $doc = $documentChunks->first()->document;
                $doc->relevance_score = $documentChunks->max('relevance_score')
                    ?? $documentChunks->max('fusion_score')
                    ?? $documentChunks->max('similarity')
                    ?? 0;
                $doc->matching_chunks_count = $documentChunks->count();
                return $doc;
            })
            ->sortByDesc('relevance_score')
            ->take($limit)
            ->values();
        if ($documents->isEmpty()) {
            return $this->retrievalService->searchDocumentsByName($topic, $limit);
        }

        return $documents;
    }

    private function handleNewSearch(string $query, array $options, array $state, ?SearchTimingData $timingData = null, ?callable $onStatus = null, ?array $precomputedEmbedding = null): array
    {
        $searchType = $options['search_type'] ?? 'hybrid';
        $limit = $options['limit'] ?? config('text_processing.retrieval.default_limit');

        $chunks = match($searchType) {
            'vector' => $this->timedVectorSearch($query, $limit, $timingData, $onStatus, $precomputedEmbedding),
            'trigram' => $this->timedTrigramSearch($query, $limit, $timingData, $onStatus),
            default => $this->timedHybridSearch($query, $limit, $timingData, $onStatus, $precomputedEmbedding)
        };
        
        $response = [
            'type' => 'search_results',
            'query' => $query,
            'search_type' => $searchType,
            'total_results' => $chunks->count(),
            'resources' => $chunks,
            'conversation_turn' => ($state['turn'] ?? 0) + 1,
            'intent' => 'new_search',
            'limit' => $limit,
        ];
        
        if (($options['include_summary'] ?? true) && $chunks->isNotEmpty()) {
            $llmStart = microtime(true);
            $response['summary'] = $this->nlgService->generateAnswer(
                $query,
                $chunks,
                $state['query_history'] ?? [],
            );
            if ($timingData) {
                $timingData->llmTimeMs = round((microtime(true) - $llmStart) * 1000, 2);
            }
        }
        
        return $response;
    }

    private function refineDocumentResults(string $query, array $state, int $limit): array
    {
        $previousResults = collect($state['last_document_results'])->map(fn($item) => (object) $item);
        $threshold = config('text_processing.search.document_name_threshold', 0.1);
        
        $refinedResults = $previousResults->map(function ($doc) use ($query) {
            $doc->relevance_score = \DB::selectOne('SELECT similarity(?, ?) as score', [$doc->filename, $query])->score ?? 0;
            return $doc;
        })
        ->filter(fn($doc) => $doc->relevance_score > $threshold)
        ->sortByDesc('relevance_score')
        ->take($limit)
        ->values();
        
        return [
            'type' => 'document_search_results',
            'query' => $query,
            'original_query' => $state['last_document_query'] ?? '',
            'total_results' => $refinedResults->count(),
            'documents' => $refinedResults,
            'is_refinement' => true,
            'original_count' => $previousResults->count(),
            'filters_applied' => ['filename_similarity'],
            'conversation_turn' => ($state['turn'] ?? 0) + 1,
            'intent' => 'find_document',
        ];
    }

    private function timedVectorSearch(string $query, int $limit, ?SearchTimingData $timingData, ?callable $onStatus = null, ?array $precomputedEmbedding = null): Collection
    {
        $onStatus && $onStatus('Searching');

        $start = microtime(true);

        if (!empty($precomputedEmbedding)) {
            $embeddingJson = json_encode($precomputedEmbedding);
            $chunks = $this->retrievalService->searchByEmbedding($query, $embeddingJson, $limit, false);
        } else {
            $chunks = $this->retrievalService->searchSimilarChunks($query, $limit, false);
        }

        if ($timingData) {
            $timingData->vectorTimeMs = round((microtime(true) - $start) * 1000, 2);
        }

        if (!$chunks->isEmpty() && $this->retrievalService instanceof RetrievalService) {
            $onStatus && $onStatus('Reranking Results');

            $rerankStart = microtime(true);
            $reranker = app(\App\Services\Search\DataRetrieval\RerankerService::class);
            if ($reranker->isEnabled()) {
                try {
                    $chunks = $reranker->rerankChunks($query, $chunks, $chunks->count());
                    if ($timingData) {
                        $timingData->rerankTimeMs = round((microtime(true) - $rerankStart) * 1000, 2);
                    }
                } catch (\Exception $e) {
                    Log::warning('Reranking failed during timed search', ['error' => $e->getMessage()]);
                }
            }
        }

        return $chunks;
    }

    private function timedTrigramSearch(string $query, int $limit, ?SearchTimingData $timingData, ?callable $onStatus = null): Collection
    {
        $onStatus && $onStatus('Searching');

        $start = microtime(true);
        $chunks = $this->retrievalService->searchTrigramMatches($query, $limit, false);

        if ($timingData) {
            $timingData->trigramTimeMs = round((microtime(true) - $start) * 1000, 2);
        }

        if (!$chunks->isEmpty()) {
            $onStatus && $onStatus('Reranking Results');

            $rerankStart = microtime(true);
            $reranker = app(\App\Services\Search\DataRetrieval\RerankerService::class);
            if ($reranker->isEnabled()) {
                try {
                    $chunks = $reranker->rerankChunks($query, $chunks);
                    if ($timingData) {
                        $timingData->rerankTimeMs = round((microtime(true) - $rerankStart) * 1000, 2);
                    }
                } catch (\Exception $e) {
                    Log::warning('Reranking failed during timed search', ['error' => $e->getMessage()]);
                }
            }
        }

        return $chunks;
    }

    private function handleMoreResults(string $query, array $options, array $state): array
    {
        Log::debug('[MoreResults] handleMoreResults entered', [
            'session_id'              => Session::getId(),
            'query'                   => $query,
            'state_last_query'        => $state['last_query']        ?? 'NOT SET',
            'state_last_limit'        => $state['last_limit']        ?? 'NOT SET',
            'state_last_search_type'  => $state['last_search_type']  ?? 'NOT SET',
            'state_turn'              => $state['turn']              ?? 'NOT SET',
            'state_last_results_count'=> is_array($state['last_results'] ?? null)
                ? count($state['last_results'])
                : 'NOT SET',
            'configured_default_limit'=> config('text_processing.retrieval.default_limit'),
        ]);

        if (empty($state['last_query'])) {
            Log::warning('[MoreResults] Aborting — no last_query in state', [
                'session_id' => Session::getId(),
            ]);
            return $this->createErrorResponse('No previous search to get more results from.');
        }

        $originalLimit = $state['last_limit'] ?? config('text_processing.retrieval.default_limit');
        $newLimit      = $originalLimit * 2;
        $searchType    = $state['last_search_type'] ?? 'hybrid';
        $originalQuery = $state['last_query'];

        Log::debug('[MoreResults] Resolved search parameters', [
            'original_limit' => $originalLimit,
            'new_limit'      => $newLimit,
            'search_type'    => $searchType,
            'original_query' => $originalQuery,
        ]);

        $searchStart = microtime(true);
        $chunks = match ($searchType) {
            'vector'  => $this->retrievalService->searchSimilarChunks($originalQuery, $newLimit),
            'trigram' => $this->retrievalService->searchTrigramMatches($originalQuery, $newLimit),
            default   => $this->retrievalService->hybridSearch($originalQuery, $newLimit),
        };
        $searchTimeMs = round((microtime(true) - $searchStart) * 1000, 2);

        Log::debug('[MoreResults] Retriever returned', [
            'search_type'        => $searchType,
            'requested_limit'    => $newLimit,
            'returned_count'     => $chunks->count(),
            'returned_chunk_ids' => $chunks->pluck('id')->all(),
            'search_time_ms'     => $searchTimeMs,
        ]);
        $additional = $chunks->skip($originalLimit)->values();

        Log::debug('[MoreResults] After skipping previously-shown chunks', [
            'skip_count'          => $originalLimit,
            'remaining_count'     => $additional->count(),
            'remaining_chunk_ids' => $additional->pluck('id')->all(),
        ]);

        return [
            'type'              => 'additional_results',
            'query'             => $query,
            'original_query'    => $originalQuery,
            'search_type'       => $searchType,
            'total_results'     => $additional->count(),
            'resources'         => $additional,
            'conversation_turn' => ($state['turn'] ?? 0) + 1,
            'intent'            => 'get_more_results',
        ];
    }
    
    private function handleSummarizeResult(string $query, array $state, ?SearchTimingData $timingData = null, bool $deferSummary = false, array $entities = []): array
    {
        if (empty($state['last_results'])) {
            return $this->createErrorResponse('No previous results to summarize.');
        }

        $resultIndex = $this->resolveResultIndex($entities, count($state['last_results']));
        
        if ($resultIndex !== null && isset($state['last_results'][$resultIndex])) {
            $chunk = (object) $state['last_results'][$resultIndex];

            if ($deferSummary) {
                return [
                    'type' => 'specific_summary',
                    'query' => $query,
                    'summarized_result_index' => $resultIndex,
                    'summary_context' => [$chunk],
                    'deferred_summary' => true,
                    'conversation_turn' => ($state['turn'] ?? 0) + 1,
                    'intent' => 'summarize_result',
                ];
            }

            $llmStart = microtime(true);
            $summary = $this->nlgService->generateAnswer($query, collect([$chunk]), $state['query_history'] ?? []);
            if ($timingData) $timingData->llmTimeMs = round((microtime(true) - $llmStart) * 1000, 2);
            
            return [
                'type' => 'specific_summary',
                'query' => $query,
                'summarized_result_index' => $resultIndex,
                'summary' => $summary,
                'conversation_turn' => ($state['turn'] ?? 0) + 1,
                'intent' => 'summarize_result',
            ];
        }
        $chunks = collect($state['last_results'])->map(fn($item) => (object) $item);

        if ($deferSummary) {
            return [
                'type' => 'general_summary',
                'query' => $query,
                'summary_context' => $chunks->all(),
                'deferred_summary' => true,
                'conversation_turn' => ($state['turn'] ?? 0) + 1,
                'intent' => 'summarize_result',
            ];
        }
        
        $llmStart = microtime(true);
        $summary = $this->nlgService->generateAnswer($query, $chunks, $state['query_history'] ?? []);
        if ($timingData) $timingData->llmTimeMs = round((microtime(true) - $llmStart) * 1000, 2);
        
        return [
            'type' => 'general_summary',
            'query' => $query,
            'summary' => $summary,
            'conversation_turn' => ($state['turn'] ?? 0) + 1,
            'intent' => 'summarize_result',
        ];
    }

    private function resolveResultIndex(array $entities, int $totalResults): ?int
    {
        if (!isset($entities['result_index'])) {
            return null;
        }

        $index = (int) $entities['result_index'];

        if ($index === -1) {
            return $totalResults - 1;
        }
        return max(0, $index - 1);
    }


    public function backfillLastAnswer(string $answer): void
    {
        $state = $this->getConversationState();

        if (empty($state['query_history'])) {
            return;
        }

        $lastIndex = array_key_last($state['query_history']);
        $state['query_history'][$lastIndex]['answer'] = mb_substr($answer, 0, 500);

        Session::put(self::SESSION_KEY, $state);
    }
    
    private function handleClarifySearch(string $query, array $state): array
    {
        $message = $this->nlgService->generateClarification(
            $query,
            $state['query_history'] ?? [],
        );

        return [
            'type' => 'clarification_needed',
            'message' => $message,
            'conversation_turn' => ($state['turn'] ?? 0) + 1,
            'intent' => 'clarify_search',
        ];
    }

    private function getConversationState(): array
    {
        $state = Session::get(self::SESSION_KEY, []);
        Log::debug('[DialogueMgmt] getConversationState', [
            'session_id'    => Session::getId(),
            'state_is_empty' => empty($state),
            'state_keys'    => array_keys($state),
            'turn'          => $state['turn'] ?? 'NOT SET',
        ]);

        return $state;
    }
    
    private function updateConversationState(string $query, array $response, string $intent): void
    {
        $state = $this->getConversationState();
        $state['turn'] = ($state['turn'] ?? 0) + 1;

        $state['query_history'][] = [
            'query' => $query,
            'intent' => $intent,
            'turn' => $state['turn'],
            'timestamp' => now()->toISOString(),
            'answer' => mb_substr($response['summary'] ?? $response['message'] ?? '', 0, 500),
        ];
        $state['query_history'] = array_slice($state['query_history'] ?? [], -self::MAX_HISTORY_LENGTH);

        if (in_array($response['type'], ['search_results', 'refined_results'])) {
            $state['last_query'] = $query;
            $state['last_results'] = $response['resources']->toArray();
            $state['last_search_type'] = $response['search_type'] ?? 'hybrid';
            $state['last_limit'] = $response['limit'] ?? config('text_processing.retrieval.default_limit');
        }

        if ($response['type'] === 'document_search_results') {
            $state['last_document_query'] = $query;
            $state['last_document_results'] = is_array($response['documents']) ? $response['documents'] : $response['documents']->toArray();
        }

        Session::put(self::SESSION_KEY, $state);
        Log::debug('[DialogueMgmt] updateConversationState completed', [
            'session_id'          => Session::getId(),
            'new_turn'            => $state['turn'],
            'query_history_count' => count($state['query_history']),
            'has_last_results'    => isset($state['last_results']),
            'session_put_verified' => !empty(Session::get(self::SESSION_KEY)),
        ]);
    }
    
    public function clearConversationState(): void
    {
        Session::forget(self::SESSION_KEY);
    }
    
    public function getConversationHistory(): array
    {
        return $this->getConversationState()['query_history'] ?? [];
    }
    
    private function createErrorResponse(string $message): array
    {
        return [
            'type' => 'error',
            'message' => $message,
            'conversation_turn' => ($this->getConversationState()['turn'] ?? 0) + 1,
        ];
    }
}