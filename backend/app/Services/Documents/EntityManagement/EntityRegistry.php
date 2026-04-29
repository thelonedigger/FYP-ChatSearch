<?php

namespace App\Services\Documents\EntityManagement;

use Illuminate\Support\Collection;

class EntityRegistry
{
    private Collection $entities;
    
    public function __construct()
    {
        $this->entities = collect();
        $this->registerCoreEntities();
    }
    
    private function registerCoreEntities(): void
    {
        $this->register('document', [
            'model' => \App\Models\Document::class,
            'chunk_model' => \App\Models\TextChunk::class,
            'foreign_key' => 'document_id',
            'searchable_fields' => ['content', 'filename'],
            'display_name' => 'Document',
            'description' => 'Text documents with semantic search capabilities',
            'chunk_config' => [
                'chunk_size' => config('text_processing.chunking.chunk_size', 1000),
                'overlap_size' => config('text_processing.chunking.overlap_size', 200),
                'min_chunk_size' => config('text_processing.chunking.min_chunk_size', 100),
            ],
            'search_config' => [
                'vector_weight' => config('text_processing.search.vector_weight', 0.7),
                'trigram_weight' => config('text_processing.search.trigram_weight', 0.3),
                'similarity_threshold' => config('text_processing.retrieval.similarity_threshold', 0.7),
                'trigram_threshold' => config('text_processing.search.trigram_threshold', 0.1),
            ],
            'processing_pipeline' => ['extractors' => ['text'], 'validators' => ['duplicate_check', 'min_length'], 'enrichers' => ['metadata']],
            'metadata_schema' => ['source' => 'string', 'author' => 'string', 'created_date' => 'datetime', 'tags' => 'array'],
        ]);
    }
    
    public function register(string $entityType, array $config): void
    {
        $this->entities->put($entityType, $this->validateConfig($config));
    }
    
    public function get(string $entityType): ?array
    {
        return $this->entities->get($entityType);
    }
    
    public function has(string $entityType): bool
    {
        return $this->entities->has($entityType);
    }
    
    public function all(): Collection
    {
        return $this->entities;
    }
    
    public function types(): array
    {
        return $this->entities->keys()->toArray();
    }
    
    public function unregister(string $entityType): void
    {
        $this->entities->forget($entityType);
    }
    
    private function validateConfig(array $config): array
    {
        foreach (['model', 'chunk_model', 'searchable_fields'] as $field) {
            if (!isset($config[$field])) throw new \InvalidArgumentException("Entity config missing: {$field}");
        }
        
        if (!class_exists($config['model'])) throw new \InvalidArgumentException("Model class not found: {$config['model']}");
        if (!class_exists($config['chunk_model'])) throw new \InvalidArgumentException("Chunk model not found: {$config['chunk_model']}");
        
        return array_merge([
            'foreign_key' => 'document_id',
            'display_name' => ucfirst($config['model']),
            'description' => '',
            'chunk_config' => [],
            'search_config' => [],
            'processing_pipeline' => [],
            'metadata_schema' => [],
        ], $config);
    }
}