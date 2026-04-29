<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class DemoController extends Controller
{
    public function quickDemo(): JsonResponse
    {
        return response()->json([
            'message' => 'Text Processing System Ready - Now with Conversational Search!',
            'usage_examples' => [
                'process_documents' => 'php artisan documents:process /path/to/files',
                'search_cli' => 'php artisan documents:search "query"',
                'api_search' => 'POST /api/v1/search {"query": "..."}',
                'conversational_search' => 'POST /api/v1/conversation/search {"query": "..."}',
                'filter_results' => 'POST /api/v1/conversation/search {"query": "filter by..."}',
                'get_more_results' => 'POST /api/v1/conversation/search {"query": "show more"}',
                'clear_conversation' => 'POST /api/v1/conversation/clear',
                'conversation_history' => 'GET /api/v1/conversation/history',
                'document_stats' => 'GET /api/v1/documents-stats',
                'list_documents' => 'GET /api/v1/documents',
            ],
            'conversation_flow_example' => [
                '1. Start' => '{"query": "Find documents about ML"}',
                '2. Filter' => '{"query": "filter by neural networks"}',
                '3. More' => '{"query": "show more results"}',
                '4. Refine' => '{"query": "find reinforcement learning instead"}',
                '5. Summarize' => '{"query": "summarize result 2"}',
            ],
            'configuration' => [
                'chunk_size' => config('text_processing.chunking.chunk_size'),
                'embedding_model' => config('text_processing.embeddings.model'),
                'source_folder' => config('text_processing.documents.source_folder'),
                'vector_weight' => config('text_processing.search.vector_weight'),
                'trigram_weight' => config('text_processing.search.trigram_weight'),
                'rrf_k' => config('text_processing.search.rrf_k'),
            ],
        ]);
    }
}