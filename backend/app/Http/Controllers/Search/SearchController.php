<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Resources\TextChunkResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\Search\DataRetrieval\RetrievalService;
use App\Services\Search\NaturalLanguageGeneration\NlgService;
use App\Services\Search\DialogueManagement\DialogueManagementService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SearchController extends Controller
{
    public function __construct(
        private RetrievalService $retrievalService,
        private NlgService $nlgService,
        private DialogueManagementService $dialogueService,
        private AuditService $auditService,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:1|max:1000',
            'limit' => 'sometimes|integer|min:1|max:100',
            'search_type' => 'sometimes|string|in:hybrid,vector,trigram',
            'include_summary' => 'sometimes|boolean',
        ]);

        $query = $request->input('query');
        $limit = $request->input('limit');
        $searchType = $request->input('search_type', 'hybrid');
        $includeSummary = $request->input('include_summary', true);

        try {
            $startTime = microtime(true);
            
            $chunks = match($searchType) {
                'vector' => $this->retrievalService->searchSimilarChunks($query, $limit),
                'trigram' => $this->retrievalService->searchTrigramMatches($query, $limit),
                default => $this->retrievalService->hybridSearch($query, $limit)
            };

            $response = [
                'query' => $query,
                'search_type' => $searchType,
                'total_results' => $chunks->count(),
                'resources' => TextChunkResource::collection($chunks),
            ];

            if ($includeSummary && $chunks->isNotEmpty()) {
                try {
                    $response['summary'] = $this->nlgService->generateAnswer($query, $chunks);
                } catch (\Exception $e) {
                    $response['summary'] = 'Summary generation failed: ' . $e->getMessage();
                }
            }

            $totalTimeMs = round((microtime(true) - $startTime) * 1000, 2);
            $this->auditService->logSearch($query, $chunks->count(), [
                'search_type' => $searchType,
                'endpoint' => 'search',
                'total_time_ms' => $totalTimeMs,
                'include_summary' => $includeSummary,
                'accessed_document_ids' => $chunks->pluck('document.id')->unique()->values()->toArray(),
            ]);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Search failed', ['error' => $e->getMessage(), 'query' => $query]);
            $this->auditService->logFailure(
                'search.failed',
                \App\Models\AuditLog::CATEGORY_SEARCH,
                $e->getMessage(),
                ['query' => $query, 'search_type' => $searchType]
            );
            
            return response()->json([
                'error' => 'Search failed',
                'message' => $e->getMessage(),
                'debug_info' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }


    public function conversationalSearch(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:1|max:1000',
            'limit' => 'sometimes|integer|min:1|max:100',
            'search_type' => 'sometimes|string|in:hybrid,vector,trigram',
            'include_summary' => 'sometimes|boolean',
            'session_id' => 'sometimes|string',
        ]);

        try {
            if ($request->has('session_id')) {
                Session::setId($request->input('session_id'));
                Session::start();
            }

            $response = $this->dialogueService->processQuery($request->input('query'), array_filter([
                'limit'           => $request->input('limit'),
                'search_type'     => $request->input('search_type', 'hybrid'),
                'include_summary' => $request->input('include_summary', true),
            ], fn($v) => $v !== null));

            $response['session_id'] = Session::getId();
            
            if ($response['type'] === 'document_search_results' && isset($response['documents'])) {
                $response['documents'] = DocumentResource::collection($response['documents']);
            }

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Conversational search failed', ['error' => $e->getMessage()]);
            return response()->json([
                'type' => 'error',
                'error' => 'Conversational search failed',
                'message' => $e->getMessage(),
                'debug_info' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Stream a conversational search response via Server-Sent Events.
     *
     * Sends structured SSE events:
     *   - "metadata" : search results, intent, timing (everything except the summary)
     *   - "token"    : individual LLM-generated content fragments
     *   - "done"     : final event with the assembled summary
     *   - "error"    : sent if something goes wrong mid-stream
     */
    public function streamConversationalSearch(Request $request): StreamedResponse
    {
        $request->validate([
            'query'        => 'required|string|min:1|max:1000',
            'limit'        => 'sometimes|integer|min:1|max:100',
            'search_type'  => 'sometimes|string|in:hybrid,vector,trigram',
            'session_id'   => 'sometimes|string',
        ]);

        if ($request->has('session_id')) {
            Session::setId($request->input('session_id'));
        }
        Session::start();

        $query   = $request->input('query');
        $options = array_filter([
            'limit'           => $request->input('limit'),
            'search_type'     => $request->input('search_type', 'hybrid'),
            'include_summary' => false,
            'defer_summary'   => true,
        ], fn($v) => $v !== null);

        return response()->stream(function () use ($query, $options) {
        try {
            $onStatus = function (string $phase): void {
                $this->sendSseEvent('status', ['phase' => $phase]);
            };
                Log::debug('[StreamSearch] Request started', [
                    'query'              => $query,
                    'session_id'         => Session::getId(),
                    'session_state_keys' => array_keys(Session::get(DialogueManagementService::SESSION_KEY, [])),
                    'query_history'      => Session::get(DialogueManagementService::SESSION_KEY . '.query_history', []),
                    'turn'               => Session::get(DialogueManagementService::SESSION_KEY . '.turn', 'NOT SET'),
                ]);

                $response = $this->dialogueService->processQuery($query, $options, $onStatus);
                Session::save();
                Log::debug('[StreamSearch] After processQuery', [
                    'query'              => $query,
                    'session_id'         => Session::getId(),
                    'response_type'      => $response['type']              ?? 'N/A',
                    'response_intent'    => $response['intent']            ?? 'N/A',
                    'response_turn'      => $response['conversation_turn'] ?? 'N/A',
                    'response_total_results' => $response['total_results'] ?? 'N/A',
                    'response_resources_count' => isset($response['resources'])
                        ? (is_countable($response['resources']) ? count($response['resources']) : $response['resources']->count())
                        : 'N/A',
                    'response_message'   => $response['message']           ?? null,
                    'session_state_keys' => array_keys(Session::get(DialogueManagementService::SESSION_KEY, [])),
                    'query_history'      => Session::get(DialogueManagementService::SESSION_KEY . '.query_history', []),
                    'session_saved'      => Session::isStarted(),
                ]);
                $this->sendSseEvent('status', ['phase' => 'Generating Answer']);

                $response['session_id'] = Session::getId();

                if ($response['type'] === 'document_search_results' && isset($response['documents'])) {
                    $response['documents'] = DocumentResource::collection($response['documents']);
                }
                $shouldStream = $this->shouldStreamSummary($response);
                $summaryContext = null;

                if ($shouldStream) {
                    $summaryContext = collect($response['summary_context'] ?? $response['resources'] ?? [])
                        ->map(fn($item) => is_object($item) ? $item : (object) $item);
                    unset($response['summary_context']);
                }

                if ($shouldStream && $summaryContext !== null && $summaryContext->isNotEmpty()) {
                    $response['sources'] = $summaryContext->values()->map(fn($chunk, $i) => [
                        'index'      => $i + 1,
                        'chunk_id'   => $chunk->id ?? null,
                        'filename'   => $chunk->document->filename ?? ($chunk->document['filename'] ?? 'Unknown'),
                        'chunk_index' => $chunk->chunk_index ?? null,
                        'content'    => \Illuminate\Support\Str::limit($chunk->content ?? '', 200),
                        'score'      => $chunk->relevance_score ?? $chunk->fusion_score ?? null,
                    ])->all();
                }

                Log::debug('[StreamSearch] Sending metadata SSE event', [
                    'session_id'        => Session::getId(),
                    'type'              => $response['type'] ?? 'N/A',
                    'intent'            => $response['intent'] ?? 'N/A',
                    'total_results'     => $response['total_results'] ?? 'N/A',
                    'resources_count'   => isset($response['resources'])
                        ? (is_countable($response['resources']) ? count($response['resources']) : $response['resources']->count())
                        : 'N/A',
                    'has_summary'       => !empty($response['summary']),
                    'has_message'       => !empty($response['message']),
                    'response_keys'     => array_keys($response),
                ]);
                $this->sendSseEvent('metadata', $response);
                $nlgDebug = null;
                $nlgTimeMs = null;

                if ($shouldStream && $summaryContext->isNotEmpty()) {
                    $fullSummary = '';
                    $nlgStart = microtime(true);

                    $conversationHistory = $this->dialogueService->getConversationHistory();

                    foreach ($this->nlgService->streamAnswer($query, $summaryContext, $conversationHistory) as $token) {
                        $fullSummary .= $token;
                        $this->sendSseEvent('token', ['content' => $token]);
                    }

                    $nlgTimeMs = round((microtime(true) - $nlgStart) * 1000, 2);
                    $this->dialogueService->backfillLastAnswer(trim($fullSummary));

                    $nlgDebug = [
                        'model'              => $this->nlgService->getModel(),
                        'provider'           => $this->nlgService->getProvider(),
                        'prompt'             => $this->nlgService->getLastPrompt(),
                        'raw_response'       => trim($fullSummary),
                        'response_time_ms'   => $nlgTimeMs,
                        'response_length'    => strlen(trim($fullSummary)),
                    ];

                    $this->sendSseEvent('done', ['summary' => trim($fullSummary)]);
                } else {
                    $this->sendSseEvent('done', []);
                }
                $timing = $response['timing'] ?? [];

                if ($nlgTimeMs !== null) {
                    $timing['nlg_time_ms'] = $nlgTimeMs;
                    $timing['total_time_ms'] = round(($timing['total_time_ms'] ?? 0) + $nlgTimeMs, 2);
                }
                $this->sendSseEvent('debug', [
                    'intent' => $response['intent_classification_metadata'] ?? null,
                    'nlg'    => $nlgDebug,
                    'timing' => $timing ?: null,
                ]);
            Session::save();
            Log::debug('[StreamSearch] Session explicitly saved', [
                'session_id' => Session::getId(),
            ]);

        } catch (\Exception $e) {
            Log::error('Streaming search failed', ['error' => $e->getMessage()]);
            $this->sendSseEvent('error', [
                'message' => $e->getMessage(),
            ]);
        }
    }, 200, [
        'Content-Type'      => 'text/event-stream',
        'Cache-Control'     => 'no-cache',
        'Connection'        => 'keep-alive',
        'X-Accel-Buffering' => 'no',
    ]);
    }

    /**
     * Determine whether the response warrants streaming an LLM summary.
     */
    private function shouldStreamSummary(array $response): bool
    {
        if (!empty($response['deferred_summary'])) {
            return true;
        }
        if (in_array($response['type'], ['search_results', 'refined_results', 'additional_results'], true)) {
            $resources = $response['resources'] ?? collect();
            $count = is_countable($resources) ? count($resources) : $resources->count();
            return $count > 0;
        }

        return false;
    }

    /**
     * Write a single SSE event to the output buffer and flush immediately.
     */
    private function sendSseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    public function clearConversation(Request $request): JsonResponse
    {
        if ($request->has('session_id')) {
            Session::setId($request->input('session_id'));
            Session::start();
        }

        $this->dialogueService->clearConversationState();

        return response()->json([
            'type' => 'conversation_cleared',
            'message' => 'Conversation history has been cleared',
            'session_id' => Session::getId(),
        ]);
    }

    public function getConversationHistory(Request $request): JsonResponse
    {
        if ($request->has('session_id')) {
            Session::setId($request->input('session_id'));
            Session::start();
        }

        return response()->json([
            'type' => 'conversation_history',
            'history' => $this->dialogueService->getConversationHistory(),
            'session_id' => Session::getId(),
        ]);
    }

    public function searchInDocument(Request $request, Document $document): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|min:1|max:1000',
            'limit' => 'sometimes|integer|min:1|max:50',
            'search_type' => 'sometimes|string|in:hybrid,vector,trigram',
            'include_summary' => 'sometimes|boolean',
        ]);

        $query = $request->input('query');
        $limit = $request->input('limit');
        $searchType = $request->input('search_type', 'hybrid');
        $includeSummary = $request->input('include_summary', true);

        try {
            $startTime = microtime(true);
            
            $chunks = match($searchType) {
                'vector' => $this->retrievalService->searchChunksInDocument($document->id, $query, $limit),
                'trigram' => $this->retrievalService->searchTrigramInDocument($document->id, $query, $limit),
                default => $this->retrievalService->hybridSearchInDocument($document->id, $query, $limit)
            };

            $response = [
                'query' => $query,
                'document' => ['id' => $document->id, 'filename' => $document->filename],
                'search_type' => $searchType,
                'total_results' => $chunks->count(),
                'resources' => TextChunkResource::collection($chunks),
            ];

            if ($includeSummary && $chunks->isNotEmpty()) {
                $response['summary'] = $this->nlgService->generateAnswer($query, $chunks);
            }

            $totalTimeMs = round((microtime(true) - $startTime) * 1000, 2);
            $this->auditService->logSearch($query, $chunks->count(), [
                'search_type' => $searchType,
                'endpoint' => 'search_in_document',
                'document_id' => $document->id,
                'document_filename' => $document->filename,
                'total_time_ms' => $totalTimeMs,
            ]);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Document search failed', ['error' => $e->getMessage(), 'document_id' => $document->id]);
            
            $this->auditService->logFailure(
                'search.in_document.failed',
                \App\Models\AuditLog::CATEGORY_SEARCH,
                $e->getMessage(),
                ['query' => $query, 'document_id' => $document->id]
            );
            
            return response()->json(['error' => 'Search failed', 'message' => $e->getMessage()], 500);
        }
    }

    public function suggestions(Request $request): JsonResponse
    {
        $request->validate(['partial_query' => 'required|string|min:2|max:100']);
        $partialQuery = $request->input('partial_query');
        
        $suggestions = \App\Models\TextChunk::select('content')
            ->where('content', 'ILIKE', "%{$partialQuery}%")
            ->limit(5)
            ->get()
            ->map(function ($chunk) use ($partialQuery) {
                foreach (preg_split('/(?<=[.!?])\s+/', $chunk->content) as $sentence) {
                    if (stripos($sentence, $partialQuery) !== false) {
                        return trim($sentence);
                    }
                }
                return substr($chunk->content, 0, 100) . '...';
            })
            ->unique()
            ->values();

        return response()->json(['suggestions' => $suggestions]);
    }
}