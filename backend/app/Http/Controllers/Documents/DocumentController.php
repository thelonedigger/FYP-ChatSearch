<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\TextChunk;
use App\Services\Documents\Processing\AsyncDocumentProcessingService;
use App\Services\Documents\EntityManagement\EntityRegistry;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentController extends Controller
{
    public function __construct(
        private AsyncDocumentProcessingService $processingService,
        private EntityRegistry $entityRegistry,
        private AuditService $auditService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $documents = Document::latest()->paginate(20);
        $this->auditService->logDataAccess('document', null, 'list', [
            'count' => $documents->count(),
            'page' => $documents->currentPage(),
            'total' => $documents->total(),
        ]);
        
        return DocumentResource::collection($documents);
    }

    public function show(Document $document): DocumentResource
    {
        $this->auditService->logDataAccess('document', $document->id, 'read', [
            'filename' => $document->filename,
            'file_size' => $document->file_size,
        ]);
        
        return new DocumentResource($document);
    }

    public function processFile(Request $request): JsonResponse
    {
        $request->validate([
            'filepath' => 'required|string',
            'async' => 'sometimes|boolean',
            'force' => 'sometimes|boolean',
        ]);

        $filePath = $request->input('filepath');
        
        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->auditService->logFailure(
                'document.process.failed',
                AuditLog::CATEGORY_DATA_MODIFICATION,
                'File not found or not readable',
                ['filepath' => $filePath]
            );
            
            return response()->json(['error' => 'File not found or not readable'], 404);
        }

        try {
            $options = ['force' => $request->input('force', false)];

            if ($request->input('async', false)) {
                $task = $this->processingService->queueDocument($filePath, $options);
                
                $this->auditService->log(
                    'document.process.queued',
                    AuditLog::CATEGORY_DATA_MODIFICATION,
                    'processing_task',
                    $task->id,
                    [
                        'filepath' => $filePath,
                        'filename' => basename($filePath),
                        'async' => true,
                        'task_id' => $task->task_id,
                    ]
                );
                
                return response()->json(['message' => 'Document queued', 'task_id' => $task->task_id, 'status' => $task->status], 202);
            }

            $task = $this->processingService->processSync($filePath, $options);
            
            if ($task->isFailed()) {
                $this->auditService->logFailure(
                    'document.process.failed',
                    AuditLog::CATEGORY_DATA_MODIFICATION,
                    $task->error_message,
                    [
                        'filepath' => $filePath,
                        'error_stage' => $task->error_stage,
                    ]
                );
                
                return response()->json(['error' => 'Processing failed', 'message' => $task->error_message, 'stage' => $task->error_stage], 500);
            }

            $document = Document::find($task->document_id);
            $this->auditService->log(
                'document.process.completed',
                AuditLog::CATEGORY_DATA_MODIFICATION,
                'document',
                $document->id,
                [
                    'filepath' => $filePath,
                    'filename' => $document->filename,
                    'total_chunks' => $document->total_chunks,
                    'processing_time_ms' => $task->getTotalDurationMs(),
                ]
            );

            return response()->json(['message' => 'Document processed', 'document' => new DocumentResource($document)], 201);
        } catch (\Exception $e) {
            $this->auditService->logFailure(
                'document.process.error',
                AuditLog::CATEGORY_DATA_MODIFICATION,
                $e->getMessage(),
                ['filepath' => $filePath]
            );
            
            return response()->json(['error' => 'Processing failed', 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(Document $document): JsonResponse
    {
        $documentData = [
            'id' => $document->id,
            'filename' => $document->filename,
            'total_chunks' => $document->total_chunks,
            'file_hash' => $document->file_hash,
        ];
        
        $document->delete();
        $this->auditService->log(
            'document.deleted',
            AuditLog::CATEGORY_DATA_MODIFICATION,
            'document',
            $documentData['id'],
            $documentData
        );
        
        return response()->json(['message' => 'Document deleted']);
    }

    public function unifiedStats(): JsonResponse
    {
        $stats = ['entities' => []];

        foreach ($this->entityRegistry->all() as $type => $config) {
            $foreignKey  = $config['foreign_key'] ?? 'document_id';
            $modelClass  = $config['model'];
            $chunkClass  = $config['chunk_model'];

            $stats['entities'][$type] = [
                'total'        => $modelClass::count(),
                'total_chunks' => $chunkClass::whereIn(
                    $foreignKey,
                    $modelClass::select('id')
                )->count(),
                'display_name' => $config['display_name'] ?? ucfirst($type),
            ];
        }

        if ($latest = Document::latest()->first()) {
            $stats['latest_processed'] = $latest->created_at;
        }

        return response()->json($stats);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total_documents' => Document::count(),
            'total_chunks' => TextChunk::count(),
            'average_chunks_per_document' => Document::avg('total_chunks'),
            'latest_processed' => Document::latest()->first()?->created_at,
        ]);
    }
}