<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Documents\FileIngestion\FileIngestionService;
use App\Services\Documents\Processing\AsyncDocumentProcessingService;
use App\Services\Documents\Processing\Exceptions\PipelineStageException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ProcessDocumentsAsyncCommand extends Command
{
    protected $signature = 'documents:process-async
                            {path? : Directory or file to process. Defaults to text_processing.documents.source_folder}
                            {--sync : Run inline instead of dispatching to the queue}
                            {--force : Re-process documents even if their content hash already exists}
                            {--queue=document-processing : Queue name for async dispatch}
                            {--delay=0 : Seconds to sleep between files in sync mode (decimals OK, e.g. 0.5)}
                            {--memory=512M : PHP memory limit for sync mode (bulk seeds need more than the CLI default)}
                            {--gc-every=50 : Force a GC cycle every N files in sync mode (0 to disable)}';

    protected $description = 'Process or queue documents for the embedding pipeline';

    public function __construct(
        private AsyncDocumentProcessingService $processingService,
        private FileIngestionService $fileIngestionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path  = $this->argument('path') ?? config('text_processing.documents.source_folder');
        $files = $this->resolveFiles($path);

        if (empty($files)) {
            $exists = is_dir($path) || is_file($path);
            $this->error($exists ? 'No supported files found.' : "Path not found: {$path}");
            return $exists ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Found ' . count($files) . ' file(s)');

        $opts = [
            'force' => (bool) $this->option('force'),
            'queue' => $this->option('queue'),
        ];

        return $this->option('sync')
            ? $this->processSync($files, $opts)
            : $this->processAsync($files, $opts);
    }

    private function resolveFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (!is_dir($path)) {
            return [];
        }

        $extensions = implode(',', $this->fileIngestionService->getSupportedExtensions());

        return File::glob($path . '/*.{' . $extensions . '}', GLOB_BRACE);
    }

    /**
     * Run files through the pipeline inline, with rate-limit pacing, memory
     * hygiene, and a fast-path skip for already-seeded content so reruns
     * don't generate audit/error log noise.
     */
    private function processSync(array $files, array $opts): int
    {
        $this->prepareLongRunningProcess();
        $seenHashes = ($opts['force'] ?? false)
            ? collect()
            : Document::pluck('file_hash')->flip();

        $delayUs = (int) ((float) $this->option('delay') * 1_000_000);
        $gcEvery = (int) $this->option('gc-every');

        $bar = $this->output->createProgressBar(count($files));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        $success = 0;
        $failed  = 0;
        $skipped = 0;
        $last    = count($files) - 1;

        foreach ($files as $i => $file) {
            $bar->setMessage(basename($file));
            $content = @file_get_contents($file);

            if ($content !== false && isset($seenHashes[Document::hashContent($content)])) {
                $skipped++;
                $bar->advance();
                continue;
            }
            unset($content);

            try {
                $task = $this->processingService->processSync($file, $opts);

                if ($task->isCompleted()) {
                    $success++;
                } else {
                    $failed++;
                    $this->newLine();
                    $this->error("Failed: {$file} — {$task->error_message}");
                }
            } catch (PipelineStageException $e) {
                if ($this->isDuplicateValidation($e)) {
                    $skipped++;
                } else {
                    $failed++;
                    $this->newLine();
                    $this->error("Failed: {$file} — {$e->getMessage()}");
                }
            } catch (\Exception $e) {
                $failed++;
                $this->newLine();
                $this->error("Failed: {$file} — {$e->getMessage()}");
            }

            $bar->advance();
            if ($gcEvery > 0 && ($i + 1) % $gcEvery === 0) {
                gc_collect_cycles();
            }
            if ($delayUs > 0 && $i < $last) {
                usleep($delayUs);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Status', 'Count'], [
            ['Completed',           $success],
            ['Skipped (duplicate)', $skipped],
            ['Failed',              $failed],
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function isDuplicateValidation(PipelineStageException $e): bool
    {
        return $e->stage === 'validation'
            && str_contains($e->getMessage(), 'Duplicate content');
    }

    /**
     * Configure the PHP process for a long-running sync seed:
     *  - Raise the memory limit (CLI defaults are too tight for thousands of files).
     *  - Disable the query log (APP_DEBUG keeps it on by default; it grows unboundedly).
     */
    private function prepareLongRunningProcess(): void
    {
        ini_set('memory_limit', (string) $this->option('memory'));
        DB::connection()->disableQueryLog();
    }

    /**
     * Dispatch each file as a queued job for a worker to consume.
     */
    private function processAsync(array $files, array $opts): int
    {
        $tasks = $this->processingService->queueBatch($files, $opts);

        $this->info("Queued {$tasks->count()} tasks\n");
        $this->table(
            ['Task ID', 'Filename', 'Status'],
            $tasks->map(fn($t) => [$t->task_id, $t->filename, $t->status])->toArray(),
        );

        $this->newLine();
        $this->info("Run 'php artisan queue:work --queue=document-processing' to process");

        return self::SUCCESS;
    }
}