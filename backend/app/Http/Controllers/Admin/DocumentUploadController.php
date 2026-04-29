<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\ProcessingTaskResource;
use App\Http\Resources\TextChunkResource;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\ProcessingTask;
use App\Services\Documents\FileIngestion\FileIngestionService;
use App\Services\Documents\Processing\AsyncDocumentProcessingService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentUploadController extends Controller
{
    public function __construct(
        private AsyncDocumentProcessingService $processingService,
        private FileIngestionService $fileIngestionService,
        private AuditService $auditService,
    ) {}

    /**
     * Accept a file upload, store it, and process it through the full pipeline.
     *
     * Returns the processing task with stage details and, on success,
     * the resulting document with its chunks for immediate inspection.
     */
    public function upload(Request $request): JsonResponse
    {
        $extensions = $this->fileIngestionService->getSupportedExtensions();
        $maxSizeKb = (int) (config('text_processing.extraction.max_file_size', 104857600) / 1024);

        $request->validate([
            'file' => [
                'required',
                'file',
                "max:{$maxSizeKb}",
            ],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if (!in_array($ext, $extensions)) {
            return response()->json([
                'message' => "Unsupported file type .{$ext}.",
                'supported' => $extensions,
            ], 422);
        }
        $storedName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
        $storagePath = $file->storeAs('documents', $storedName, 'local');
        $fullPath = Storage::disk('local')->path($storagePath);

        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);
        $task = null;
        $pipelineException = null;

        try {
            $task = $this->processingService->processSync($fullPath, [
                'force' => $force,
            ]);
        } catch (\Exception $e) {
            $pipelineException = $e;
            $task = ProcessingTask::where('filepath', $fullPath)
                ->latest()
                ->first();
        }
        if (!$task) {
            @unlink($fullPath);

            $this->auditService->logFailure(
                'document.upload.error',
                AuditLog::CATEGORY_DATA_MODIFICATION,
                $pipelineException?->getMessage() ?? 'Unknown error',
                ['filename' => $file->getClientOriginalName()],
            );

            return response()->json([
                'error'   => 'Processing failed before task creation',
                'message' => $pipelineException?->getMessage() ?? 'Unknown error',
            ], 500);
        }

        $task->load('logs');
        if ($task->isFailed()) {
            $this->auditService->logFailure(
                'document.upload.failed',
                AuditLog::CATEGORY_DATA_MODIFICATION,
                $task->error_message,
                [
                    'filename' => $file->getClientOriginalName(),
                    'stage'    => $task->error_stage,
                    'task_id'  => $task->task_id,
                ],
            );

            return response()->json([
                'task'  => new ProcessingTaskResource($task),
                'error' => $task->error_message,
                'stage' => $task->error_stage,
            ], 422);
        }
        $document = Document::with('textChunks')->find($task->document_id);

        $this->auditService->log(
            'document.upload.completed',
            AuditLog::CATEGORY_DATA_MODIFICATION,
            'document',
            $document->id,
            [
                'filename'      => $document->filename,
                'total_chunks'  => $document->total_chunks,
                'duration_ms'   => $task->getTotalDurationMs(),
                'uploaded_name' => $file->getClientOriginalName(),
            ],
        );

        return response()->json([
            'task'     => new ProcessingTaskResource($task),
            'document' => new DocumentResource($document),
            'chunks'   => TextChunkResource::collection($document->textChunks),
        ], 201);
    }

    /**
     * Return all chunks belonging to a document for inspection.
     */
    public function chunks(Document $document): JsonResponse
    {
        $chunks = $document->textChunks()->orderBy('chunk_index')->get();

        return response()->json([
            'document' => new DocumentResource($document),
            'chunks'   => TextChunkResource::collection($chunks),
            'total'    => $chunks->count(),
        ]);
    }

    /**
     * List recent processing tasks for the admin dashboard.
     */
    public function tasks(Request $request): JsonResponse
    {
        $tasks = ProcessingTask::with('logs')
            ->latest()
            ->limit($request->integer('limit', 20))
            ->get();

        return response()->json([
            'tasks' => ProcessingTaskResource::collection($tasks),
        ]);
    }

    /**
     * Return supported file extensions so the frontend can validate client-side.
     */
    public function supportedTypes(): JsonResponse
    {
        return response()->json([
            'extensions'    => $this->fileIngestionService->getSupportedExtensions(),
            'max_file_size' => config('text_processing.extraction.max_file_size', 104857600),
        ]);
    }
}