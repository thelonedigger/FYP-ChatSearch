<?php

namespace App\Services\Documents\Processing\Stages;

use App\Models\Document;
use App\Models\ProcessingTask;
use App\Models\TextChunk;
use App\Services\Documents\Processing\Contracts\PipelineStageInterface;
use App\Services\Documents\Processing\DTOs\PipelineContext;
use App\Services\Documents\Processing\Exceptions\PipelineStageException;
use Illuminate\Support\Facades\DB;

class StorageStage implements PipelineStageInterface
{
    public function getName(): string { return ProcessingTask::STAGE_STORAGE; }

    public function process(PipelineContext $ctx): PipelineContext
    {
        if (!$ctx->hasChunks() || !$ctx->hasEmbeddings()) {
            throw PipelineStageException::storageFailed('Missing chunks or embeddings');
        }

        try {
            $ctx->documentId = DB::transaction(function () use ($ctx) {
                $contentHash = $ctx->metadata['content_hash'];
                $force = $ctx->task->options['force'] ?? false;
                if ($force) {
                    Document::where('file_hash', $contentHash)->delete();
                }

                $doc = Document::create([
                    'filename'     => $ctx->task->filename,
                    'filepath'     => $ctx->filePath,
                    'content'      => $ctx->content,
                    'file_hash'    => $contentHash,
                    'total_chunks' => count($ctx->chunks),
                    'metadata'     => $ctx->metadata,
                ]);

                $count = 0;
                foreach ($ctx->chunks as $i => $chunk) {
                    if (empty($ctx->embeddings[$i])) continue;

                    TextChunk::create([
                        'document_id'    => $doc->id,
                        'content'        => $chunk['content'],
                        'chunk_index'    => $chunk['chunk_index'],
                        'start_position' => $chunk['start_position'],
                        'end_position'   => $chunk['end_position'],
                        'embedding'      => $ctx->embeddings[$i],
                        'metadata'       => array_merge(
                            $chunk['metadata'] ?? [],
                            ['source_task_id' => $ctx->task->task_id],
                        ),
                    ]);
                    $count++;
                }

                $doc->update(['total_chunks' => $count]);
                return $doc->id;
            });

            return $ctx->setStageData($this->getName(), [
                'document_id'   => $ctx->documentId,
                'chunks_stored' => count($ctx->chunks),
            ]);
        } catch (\Exception $e) {
            throw PipelineStageException::storageFailed($e->getMessage(), $e);
        }
    }

    public function shouldSkip(PipelineContext $ctx): bool { return $ctx->documentId !== null; }
    public function rollback(PipelineContext $ctx): void { if ($ctx->documentId) { Document::where('id', $ctx->documentId)->delete(); $ctx->documentId = null; } }
}