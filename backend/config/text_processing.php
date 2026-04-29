<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Text Processing Configuration
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Jina AI Configuration
    |--------------------------------------------------------------------------
    */
    'jina' => [
        'api_key' => env('JINA_API_KEY'),
        'base_url' => env('JINA_API_BASE_URL', 'https://api.jina.ai/v1'),
        'timeout' => env('JINA_API_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking Configuration
    |--------------------------------------------------------------------------
    */
    'chunking' => [
        'chunk_size' => env('CHUNK_SIZE', 1000),
        'overlap_size' => env('CHUNK_OVERLAP', 200),
        'min_chunk_size' => env('MIN_CHUNK_SIZE', 100),
        'overlap_sentences' => env('CHUNK_OVERLAP_SENTENCES', 2),
        'semantic_threshold' => env('SEMANTIC_CHUNK_THRESHOLD', 0.45),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Configuration (Jina AI)
    |--------------------------------------------------------------------------
    */
    'embeddings' => [
        'provider' => env('EMBEDDING_PROVIDER', 'jina'),
        'dimensions' => env('EMBEDDING_DIMENSIONS', 1024),
        'batch_size' => env('EMBEDDING_BATCH_SIZE', 100),
        'providers' => [
            'jina' => [
                'model' => env('JINA_EMBEDDING_MODEL', 'jina-embeddings-v3'),
            ],
            'ollama' => [
                'model' => env('OLLAMA_EMBEDDING_MODEL', 'harrier-oss-v1-0.6b'),
                'timeout' => env('OLLAMA_EMBEDDING_TIMEOUT', 120),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reranker Configuration (Jina AI)
    |--------------------------------------------------------------------------
    */
    'reranker' => [
        'provider' => env('RERANKER_PROVIDER', 'jina'),
        'enabled' => env('RERANKER_ENABLED', true),
        'top_k' => env('RERANKER_TOP_K', 50),
        'return_top_k' => env('RERANKER_RETURN_TOP_K', 10),
        'providers' => [
            'jina' => [
                'model' => env('JINA_RERANKER_MODEL', 'jina-reranker-v2-base-multilingual'),
            ],
            'ollama' => [
                'model' => env('OLLAMA_RERANKER_MODEL', 'Qwen3-Reranker-0.6B'),
                'timeout' => env('OLLAMA_RERANKER_TIMEOUT', 120),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration (local inference server)
    |--------------------------------------------------------------------------
    | Shared base URL for all services that use Ollama as their provider.
    | Individual services can override via their own 'base_url' key.
    */
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://ollama:11434'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Configuration (for NLG/Answer Generation)
    |--------------------------------------------------------------------------
    */
    'llm' => [
        'max_tokens' => env('LLM_MAX_TOKENS', 500),
        'temperature' => env('LLM_TEMPERATURE', 1.0),

        'profiles' => [
            'local' => [
                'provider' => env('LLM_LOCAL_PROVIDER', 'ollama'),
                'model'    => env('LLM_LOCAL_MODEL', 'gemma3:12b'),
                'api_key'  => env('LLM_LOCAL_API_KEY'),
                'base_url' => env('LLM_LOCAL_BASE_URL'),
                'timeout'  => env('LLM_LOCAL_TIMEOUT', 120),
            ],
            'cloud' => [
                'provider' => env('LLM_CLOUD_PROVIDER', 'openai'),
                'model'    => env('LLM_CLOUD_MODEL', 'gpt-4o-mini'),
                'api_key'  => env('LLM_CLOUD_API_KEY', env('OPENAI_API_KEY')),
                'base_url' => env('LLM_CLOUD_BASE_URL'),
                'timeout'  => env('LLM_CLOUD_TIMEOUT', 30),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Intent Classification Configuration
    |--------------------------------------------------------------------------
    */
    'intent_classification' => [
        'max_tokens' => env('INTENT_LLM_MAX_TOKENS', 500),
        'temperature' => env('INTENT_LLM_TEMPERATURE', 1.0),

        'profiles' => [
            'local' => [
                'provider' => env('INTENT_LOCAL_PROVIDER', 'ollama'),
                'model'    => env('INTENT_LOCAL_MODEL', 'phi4-mini:3.8b'),
                'api_key'  => env('INTENT_LOCAL_API_KEY'),
                'base_url' => env('INTENT_LOCAL_BASE_URL'),
                'timeout'  => env('INTENT_LOCAL_TIMEOUT', 30),
            ],
            'cloud' => [
                'provider' => env('INTENT_CLOUD_PROVIDER', 'openai'),
                'model'    => env('INTENT_CLOUD_MODEL', 'gpt-4o-mini'),
                'api_key'  => env('INTENT_CLOUD_API_KEY', env('OPENAI_API_KEY')),
                'base_url' => env('INTENT_CLOUD_BASE_URL'),
                'timeout'  => env('INTENT_CLOUD_TIMEOUT', 10),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Source Configuration
    |--------------------------------------------------------------------------
    */
    'documents' => [
        'source_folder' => env('DOCUMENTS_SOURCE_FOLDER', storage_path('app/documents')),
        'allowed_extensions' => ['txt'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval Configuration
    |--------------------------------------------------------------------------
    */
    'retrieval' => [
        'default_limit' => env('RETRIEVAL_DEFAULT_LIMIT', 10),
        'similarity_threshold' => env('SIMILARITY_THRESHOLD', 0.3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Configuration
    |--------------------------------------------------------------------------
    */
    'search' => [
        'vector_weight' => env('SEARCH_VECTOR_WEIGHT', 0.5),
        'trigram_weight' => env('SEARCH_TRIGRAM_WEIGHT', 0.5),
        'trigram_threshold' => env('TRIGRAM_THRESHOLD', 0.1),
        'rrf_k' => env('RRF_K', 60),
        'document_name_threshold' => env('DOCUMENT_NAME_THRESHOLD', 0.1),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Extraction Configuration
    |--------------------------------------------------------------------------
    */
    'extraction' => [
        'min_pdf_content_length' => env('MIN_PDF_CONTENT_LENGTH', 50),
        'min_docx_content_length' => env('MIN_DOCX_CONTENT_LENGTH', 50),
        'min_markdown_content_length' => env('MIN_MARKDOWN_CONTENT_LENGTH', 50),
        'min_html_content_length' => env('MIN_HTML_CONTENT_LENGTH', 50),
        'max_file_size' => env('MAX_FILE_SIZE', 104857600), // 100MB in bytes
        'docx_include_headers_footers' => env('DOCX_INCLUDE_HEADERS_FOOTERS', true), 
        'docx_table_delimiter' => env('DOCX_TABLE_DELIMITER', ' | '),
        'markdown_strip_syntax' => env('MARKDOWN_STRIP_SYNTAX', false),
        'markdown_extract_frontmatter' => env('MARKDOWN_EXTRACT_FRONTMATTER', true),
        'html_include_links' => env('HTML_INCLUDE_LINKS', false),
        'html_preserve_structure' => env('HTML_PRESERVE_STRUCTURE', true),
        'html_exclude_tags' => ['script', 'style', 'nav', 'header', 'footer', 'aside'],
    ],
];