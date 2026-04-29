<?php

namespace App\Console\Commands;

use App\Services\Search\DataRetrieval\RetrievalService;
use Illuminate\Console\Command;

class SearchDocumentsCommand extends Command
{
    protected $signature = 'documents:search {query} {--limit=10}';
    protected $description = 'Search documents using semantic similarity';

    public function __construct(private RetrievalService $retrievalService) { parent::__construct(); }

    public function handle(): int
    {
        $query = $this->argument('query');
        $this->info("Searching for: \"{$query}\"\n");

        try {
            $chunks = $this->retrievalService->searchSimilarChunks($query, (int)$this->option('limit'));

            if ($chunks->isEmpty()) {
                $this->warn('No results found.');
                return self::SUCCESS;
            }

            $this->info("Found {$chunks->count()} results:\n");

            foreach ($chunks as $i => $chunk) {
                $this->line("<comment>Result " . ($i + 1) . "</comment>");
                $this->line("<info>Document:</info> {$chunk->document->filename}");
                $this->line("<info>Similarity:</info> " . round($chunk->similarity * 100, 2) . "%");
                $this->line("<info>Content:</info>\n" . wordwrap($chunk->content, 80) . "\n");
            }
        } catch (\Exception $e) {
            $this->error('Search failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}