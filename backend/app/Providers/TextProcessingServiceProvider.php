<?php

namespace App\Providers;

use App\Services\LLM\LlmClient;
use App\Services\Search\DialogueManagement\DialogueManagementService;
use App\Services\Search\DataRetrieval\{RetrievalService, RankFusionService, RerankerService};
use App\Services\Search\DataRetrieval\Contracts\RerankerProviderInterface;
use App\Services\Search\DataRetrieval\Providers\{JinaRerankerProvider, OllamaRerankerProvider};
use App\Services\Documents\EntityManagement\EntityRegistry;
use App\Services\Search\NaturalLanguageUnderstanding\{NluService, LlmBasedIntentClassifier};
use App\Services\Search\NaturalLanguageUnderstanding\Contracts\IntentClassifierInterface;
use App\Services\Search\NaturalLanguageGeneration\NlgService;
use App\Services\Documents\Processing\{EmbeddingService, TextChunkingService, PipelineExecutorService, AsyncDocumentProcessingService};
use App\Services\Documents\Processing\Contracts\EmbeddingProviderInterface;
use App\Services\Documents\Processing\Providers\{JinaEmbeddingProvider, OllamaEmbeddingProvider};
use App\Services\Documents\Processing\Strategies\SentenceTokenizer;
use App\Services\Documents\Processing\Strategies\StructuralChunkingStrategy;
use App\Services\Documents\Processing\Strategies\SemanticChunkingStrategy;
use App\Services\Documents\Processing\Stages\{ExtractionStage, ValidationStage, ChunkingStage, EmbeddingStage, StorageStage};
use App\Services\Documents\FileIngestion\FileIngestionService;
use App\Services\Analytics\MetricsService;
use App\Services\Audit\{AuditService, DataRetentionService};
use Illuminate\Support\ServiceProvider;
use App\Models\SystemSetting;

class TextProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $singletons = [
            FileIngestionService::class, EntityRegistry::class, TextChunkingService::class, EmbeddingService::class,
            RankFusionService::class, RerankerService::class, RetrievalService::class,
            NluService::class, DialogueManagementService::class,
            ExtractionStage::class, ValidationStage::class, ChunkingStage::class, EmbeddingStage::class, StorageStage::class,
            PipelineExecutorService::class, AsyncDocumentProcessingService::class, MetricsService::class,
            AuditService::class, DataRetentionService::class, SentenceTokenizer::class, StructuralChunkingStrategy::class, SemanticChunkingStrategy::class,
        ];

        foreach ($singletons as $class) {
            $this->app->singleton($class);
        }

        $this->app->singleton(EmbeddingProviderInterface::class, function () {
            return match (config('text_processing.embeddings.provider', 'jina')) {
                'ollama' => new OllamaEmbeddingProvider(),
                default => new JinaEmbeddingProvider(),
            };
        });

        $this->app->singleton(RerankerProviderInterface::class, function () {
            return match (config('text_processing.reranker.provider', 'jina')) {
                'ollama' => new OllamaRerankerProvider(),
                default => new JinaRerankerProvider(),
            };
        });

        $this->app->singleton(IntentClassifierInterface::class, LlmBasedIntentClassifier::class);

        $this->app->when(NlgService::class)
            ->needs(LlmClient::class)
            ->give(function () {
                $mode = SystemSetting::getValue('llm_mode', 'local');
                return LlmClient::fromProfile('text_processing.llm', $mode);
            });

        $this->app->when(LlmBasedIntentClassifier::class)
            ->needs(LlmClient::class)
            ->give(function () {
                $mode = SystemSetting::getValue('llm_mode', 'local');
                return LlmClient::fromProfile('text_processing.intent_classification', $mode);
            });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\SearchDocumentsCommand::class,
                \App\Console\Commands\ProcessDocumentsAsyncCommand::class,
                \App\Console\Commands\ListEntitiesCommand::class,
                \App\Console\Commands\TestJinaApiCommand::class,
                \App\Console\Commands\AuditRetentionCommand::class,
                \App\Console\Commands\AuditReportCommand::class,
            ]);
        }
    }
}