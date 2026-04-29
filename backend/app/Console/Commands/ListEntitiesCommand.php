<?php

namespace App\Console\Commands;

use App\Services\Documents\EntityManagement\EntityRegistry;
use Illuminate\Console\Command;

class ListEntitiesCommand extends Command
{
    protected $signature = 'entities:list';
    protected $description = 'List registered entity types';

    public function __construct(private EntityRegistry $entityRegistry) { parent::__construct(); }

    public function handle(): int
    {
        $this->info("Registered Entity Types:\n");
        $entities = $this->entityRegistry->all();
        
        if ($entities->isEmpty()) {
            $this->warn("No entities registered.");
            return self::SUCCESS;
        }
        
        foreach ($entities as $type => $config) {
            $this->line("<comment>{$type}</comment>");
            $this->line("  Display Name: " . ($config['display_name'] ?? 'N/A'));
            $this->line("  Model: {$config['model']}");
            $this->line("  Chunk Model: {$config['chunk_model']}");
            $this->line("  Searchable: " . implode(', ', $config['searchable_fields']));
            $this->line("  Vector Weight: " . ($config['search_config']['vector_weight'] ?? 'N/A'));
            $this->line("  Trigram Weight: " . ($config['search_config']['trigram_weight'] ?? 'N/A') . "\n");
        }
        
        $this->info("Total: {$entities->count()} entity type(s)");
        return self::SUCCESS;
    }
}