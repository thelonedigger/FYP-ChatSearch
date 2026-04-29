<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\TextChunk;
use App\Services\Documents\Processing\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BenchmarkSeedCommand extends Command
{
    protected $signature = 'benchmark:seed
                            {--batch-size=20 : Texts per embedding API call}
                            {--delay=4 : Seconds to sleep between API calls}
                            {--reset : Delete existing benchmark data and re-seed}';

    protected $description = 'Seed the BEIR SciFact corpus into the database for benchmarking';

    private const DATASET_PATH = 'benchmark/scifact';
    private const CORPUS_FILE = 'corpus.jsonl';

    public function handle(EmbeddingService $embeddingService): int
    {
        $basePath = storage_path('app/' . self::DATASET_PATH);
        $corpusPath = $basePath . '/' . self::CORPUS_FILE;

        if (!file_exists($corpusPath)) {
            $this->error("Corpus file not found at: {$corpusPath}");
            $this->line("Place the SciFact dataset in: {$basePath}/");
            return self::FAILURE;
        }
        if ($this->option('reset')) {
            $this->warn('Resetting existing benchmark data...');
            $doc = Document::where('file_hash', 'beir_scifact_benchmark_corpus')->first();
            if ($doc) {
                $doc->delete();
                $this->info("Deleted existing benchmark document (ID: {$doc->id}) and its chunks.");
            }
        }
        $this->info('Parsing SciFact corpus...');
        $corpus = $this->parseCorpus($corpusPath);
        $this->info("Loaded {$corpus->count()} documents from corpus.");
        $document = Document::where('file_hash', 'beir_scifact_benchmark_corpus')->first();

        if (!$document) {
            $document = Document::create([
                'filename'     => 'scifact_benchmark_corpus',
                'filepath'     => self::DATASET_PATH,
                'content'      => "BEIR SciFact benchmark corpus ({$corpus->count()} abstracts)",
                'file_hash'    => 'beir_scifact_benchmark_corpus',
                'total_chunks' => $corpus->count(),
                'metadata'     => [
                    'type'        => 'benchmark',
                    'dataset'     => 'beir_scifact',
                    'corpus_size' => $corpus->count(),
                    'source'      => 'https://public.ukp.informatik.tu-darmstadt.de/thakur/BEIR/datasets/scifact.zip',
                ],
            ]);
            $this->info("Created benchmark document (ID: {$document->id})");
        } else {
            $this->info("Found existing benchmark document (ID: {$document->id})");
        }
        $existingCorpusIds = TextChunk::where('document_id', $document->id)
            ->pluck('metadata')
            ->map(fn($meta) => $meta['beir_corpus_id'] ?? null)
            ->filter()
            ->flip()  // Use flip for O(1) lookups
            ->toArray();

        $remaining = $corpus->filter(fn($entry) => !isset($existingCorpusIds[$entry['_id']]));

        if ($remaining->isEmpty()) {
            $this->info("All {$corpus->count()} corpus entries are already embedded. Nothing to do.");
            return self::SUCCESS;
        }

        $alreadyDone = $corpus->count() - $remaining->count();
        $this->info("Resuming: {$alreadyDone} already embedded, {$remaining->count()} remaining.");
        $batchSize = (int) $this->option('batch-size');
        $delay     = (int) $this->option('delay');
        $batches   = $remaining->values()->chunk($batchSize);
        $bar       = $this->output->createProgressBar($remaining->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Starting...');
        $bar->start();
        $chunkIndex = $alreadyDone;
        $position   = $alreadyDone * 1000;  // Approximate, sufficient for benchmark

        foreach ($batches as $batchNum => $batch) {
            $texts = $batch->map(fn($e) => trim(($e['title'] ?? '') . "\n" . ($e['text'] ?? '')))->values()->toArray();

            $bar->setMessage("Embedding batch " . ($batchNum + 1) . "/" . $batches->count());

            try {
                $embeddings = $embeddingService->generateEmbeddings($texts, 'retrieval.passage');
            } catch (\Exception $e) {
                $this->newLine(2);
                $this->error("Embedding failed at batch " . ($batchNum + 1) . ": " . $e->getMessage());
                $this->newLine();

                $storedSoFar = TextChunk::where('document_id', $document->id)->count();
                $this->warn("Progress saved: {$storedSoFar}/{$corpus->count()} chunks stored.");
                $this->info("Simply re-run the same command to resume from where you left off.");

                return self::FAILURE;
            }

            DB::transaction(function () use ($batch, $embeddings, $texts, $document, &$chunkIndex, &$position) {
                foreach ($batch->values() as $i => $entry) {
                    if (empty($embeddings[$i])) {
                        continue;
                    }

                    $content       = $texts[$i];
                    $contentLength = strlen($content);

                    TextChunk::create([
                        'document_id'    => $document->id,
                        'content'        => $content,
                        'chunk_index'    => $chunkIndex,
                        'start_position' => $position,
                        'end_position'   => $position + $contentLength,
                        'embedding'      => $embeddings[$i],
                        'metadata'       => [
                            'beir_corpus_id' => $entry['_id'],
                            'title'          => $entry['title'] ?? null,
                            'benchmark'      => 'scifact',
                        ],
                    ]);

                    $chunkIndex++;
                    $position += $contentLength;
                }
            });

            $bar->advance($batch->count());
            if ($batchNum < $batches->count() - 1) {
                sleep($delay);
            }
        }

        $bar->setMessage('Done!');
        $bar->finish();
        $this->newLine(2);

        $storedCount = TextChunk::where('document_id', $document->id)->count();
        $this->info("Seeding complete: {$storedCount}/{$corpus->count()} chunks stored under Document ID {$document->id}.");

        return self::SUCCESS;
    }

    /**
     * Parse the BEIR corpus.jsonl file into a collection of entries.
     */
    private function parseCorpus(string $path): \Illuminate\Support\Collection
    {
        $entries = collect();
        $handle  = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $entry = json_decode($line, true);
            if ($entry && isset($entry['_id'])) {
                $entries->push($entry);
            }
        }

        fclose($handle);

        return $entries;
    }
}