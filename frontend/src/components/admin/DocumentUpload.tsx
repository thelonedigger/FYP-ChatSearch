import { useState, useRef, useCallback, useEffect } from 'react'
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { SmallText, Caption } from '@/components/common/Typography'
import { Stack } from '@/components/common/Container'
import { ScrollArea } from '@/components/ui/scroll-area'
import {
  Upload,
  FileText,
  CheckCircle2,
  XCircle,
  Loader2,
  ChevronDown,
  ChevronRight,
  RotateCcw,
  Eye,
} from 'lucide-react'
import { adminApi } from '@/services/api'

interface StageInfo {
  status: string
  started_at: string | null
  completed_at: string | null
  duration_ms: number | null
  error: string | null
  [key: string]: unknown
}

interface TaskResult {
  task_id: string
  filename: string
  status: string
  progress_percent: number
  current_stage: string | null
  stages: Record<string, StageInfo>
  total_duration_ms: number | null
  error_message?: string
  error_stage?: string
  document_id?: number | null
}

interface ChunkResult {
  id: number
  content: string
  chunk_index: number
  word_count: number
  metadata: Record<string, unknown> | null
}

interface UploadResponse {
  task: TaskResult
  document?: { id: number; filename: string; total_chunks: number; metadata: Record<string, unknown> }
  chunks?: ChunkResult[]
  error?: string
  stage?: string
}

const STAGE_LABELS: Record<string, string> = {
  extraction: 'Extraction',
  validation: 'Validation',
  chunking: 'Chunking',
  embedding: 'Embedding',
  storage: 'Storage',
}

const STAGE_ORDER = ['extraction', 'validation', 'chunking', 'embedding', 'storage']

const formatBytes = (bytes: number): string => {
  if (bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(1024))
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`
}

const formatMs = (ms: number | null): string => {
  if (ms === null) return '—'
  return ms < 1000 ? `${Math.round(ms)}ms` : `${(ms / 1000).toFixed(2)}s`
}

function StageIndicator({ name, info }: { name: string; info: StageInfo }) {
  const status = info.status
  return (
    <div className="flex items-center justify-between py-1.5">
      <div className="flex items-center gap-2">
        {status === 'completed' && <CheckCircle2 className="h-4 w-4 text-green-500" />}
        {status === 'processing' && <Loader2 className="h-4 w-4 text-blue-500 animate-spin" />}
        {status === 'failed' && <XCircle className="h-4 w-4 text-red-500" />}
        {status === 'pending' && <div className="h-4 w-4 rounded-full border-2 border-muted-foreground/30" />}
        <SmallText className={status === 'processing' ? 'font-medium' : ''}>
          {STAGE_LABELS[name] ?? name}
        </SmallText>
      </div>
      <div className="flex items-center gap-2">
        {info.duration_ms != null && (
          <Caption>{formatMs(info.duration_ms)}</Caption>
        )}
        {status === 'failed' && info.error && (
          <Badge variant="secondary" className="text-xs">Failed</Badge>
        )}
      </div>
    </div>
  )
}

function ChunkCard({ chunk, index }: { chunk: ChunkResult; index: number }) {
  const [expanded, setExpanded] = useState(false)
  const preview = chunk.content.slice(0, 200)
  const hasMore = chunk.content.length > 200

  return (
    <div className="rounded-lg border border-border p-3 space-y-2">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Badge variant="secondary" className="text-xs font-mono">#{index}</Badge>
          <Caption>{chunk.word_count} words · {chunk.content.length} chars</Caption>
        </div>
        {hasMore && (
          <Button variant="ghost" size="sm" onClick={() => setExpanded(!expanded)} className="h-6 px-2">
            {expanded ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />}
            <span className="text-xs ml-1">{expanded ? 'Less' : 'More'}</span>
          </Button>
        )}
      </div>
      <p className="text-sm text-muted-foreground leading-relaxed whitespace-pre-wrap break-words">
        {expanded ? chunk.content : preview}{!expanded && hasMore ? '…' : ''}
      </p>
    </div>
  )
}

export function DocumentUpload() {
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [dragActive, setDragActive] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<UploadResponse | null>(null)
  const [supportedExts, setSupportedExts] = useState<string[]>([])
  const [maxFileSize, setMaxFileSize] = useState(104857600)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [forceReprocess, setForceReprocess] = useState(false)
  const [chunkViewOpen, setChunkViewOpen] = useState(false)

  useEffect(() => {
    adminApi.getSupportedTypes().then(data => {
      setSupportedExts(data.extensions)
      setMaxFileSize(data.max_file_size)
    }).catch(() => {
      setSupportedExts(['txt', 'pdf', 'docx', 'md', 'html'])
    })
  }, [])

  const resetState = useCallback(() => {
    setResult(null)
    setError(null)
    setSelectedFile(null)
    setForceReprocess(false)
    setChunkViewOpen(false)
    if (fileInputRef.current) fileInputRef.current.value = ''
  }, [])

  const validateFile = useCallback((file: File): string | null => {
    const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
    if (supportedExts.length && !supportedExts.includes(ext)) {
      return `Unsupported file type .${ext}. Supported: ${supportedExts.join(', ')}`
    }
    if (file.size > maxFileSize) {
      return `File too large (${formatBytes(file.size)}). Maximum: ${formatBytes(maxFileSize)}`
    }
    return null
  }, [supportedExts, maxFileSize])

  const handleUpload = useCallback(async (file: File) => {
    const validationError = validateFile(file)
    if (validationError) {
      setError(validationError)
      return
    }

    setSelectedFile(file)
    setUploading(true)
    setError(null)
    setResult(null)
    setChunkViewOpen(false)

    try {
      const response = await adminApi.uploadDocument(file, forceReprocess)
      setResult(response)

      if (response.error || response.task?.status === 'failed') {
        setError(response.error ?? response.task?.error_message ?? 'Processing failed')
      }
    } catch (e: any) {
      const responseData = e?.response?.data

      if (responseData?.task) {
        setResult(responseData)
        setError(responseData.error ?? responseData.message ?? 'Processing failed')
      } else {
        const msg = responseData?.message
          ?? responseData?.error
          ?? responseData?.errors?.file?.[0]
          ?? e?.message
          ?? 'Upload failed'
        setError(msg)
      }
    } finally {
      setUploading(false)
    }
  }, [forceReprocess, validateFile])

  const handleDrag = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    if (e.type === 'dragenter' || e.type === 'dragover') setDragActive(true)
    else if (e.type === 'dragleave') setDragActive(false)
  }, [])

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setDragActive(false)
    if (e.dataTransfer.files?.[0]) handleUpload(e.dataTransfer.files[0])
  }, [handleUpload])

  const handleFileSelect = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files?.[0]) handleUpload(e.target.files[0])
  }, [handleUpload])

  const task = result?.task
  const isSuccess = task?.status === 'completed'
  const totalChunks = result?.chunks?.length ?? 0

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg flex items-center gap-2">
          <Upload className="h-5 w-5" />
          Document Upload & Processing
        </CardTitle>
      </CardHeader>
      <CardContent>
        <Stack spacing="md">
          {}
          {!uploading && !result && (
            <>
              <div
                className={`relative rounded-lg border-2 border-dashed p-8 text-center transition-colors cursor-pointer ${
                  dragActive
                    ? 'border-primary bg-primary/5'
                    : 'border-muted-foreground/25 hover:border-muted-foreground/50'
                }`}
                onDragEnter={handleDrag}
                onDragOver={handleDrag}
                onDragLeave={handleDrag}
                onDrop={handleDrop}
                onClick={() => fileInputRef.current?.click()}
              >
                <input
                  ref={fileInputRef}
                  type="file"
                  onChange={handleFileSelect}
                  accept={supportedExts.map(e => `.${e}`).join(',')}
                  className="hidden"
                />
                <FileText className="h-10 w-10 mx-auto mb-3 text-muted-foreground/50" />
                <SmallText className="font-medium">
                  Drop a file here or click to browse
                </SmallText>
                <Caption className="mt-1">
                  Supported: {supportedExts.length ? supportedExts.join(', ') : '...'} · Max {formatBytes(maxFileSize)}
                </Caption>
              </div>

              <label className="flex items-center gap-2 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={forceReprocess}
                  onChange={e => setForceReprocess(e.target.checked)}
                  className="rounded border-muted-foreground/30"
                />
                <Caption>Force reprocess (skip duplicate check)</Caption>
              </label>
            </>
          )}

          {}
          {uploading && (
            <div className="rounded-lg border border-border p-6 space-y-4">
              <div className="flex items-center gap-3">
                <Loader2 className="h-5 w-5 animate-spin text-primary" />
                <div>
                  <SmallText className="font-medium">Processing {selectedFile?.name}...</SmallText>
                  <Caption>Running through the pipeline. This may take a moment.</Caption>
                </div>
              </div>
            </div>
          )}

          {}
          {error && (
            <div className="rounded-lg border border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/30 p-4 space-y-2">
              <div className="flex items-center gap-2">
                <XCircle className="h-4 w-4 text-red-500 shrink-0" />
                <SmallText className="text-red-700 dark:text-red-400 font-medium">{error}</SmallText>
              </div>
              {result?.stage && (
                <Caption className="text-red-600 dark:text-red-400 ml-6">Failed at stage: {STAGE_LABELS[result.stage] ?? result.stage}</Caption>
              )}
            </div>
          )}

          {}
          {task && (
            <div className="space-y-4">
              {}
              {isSuccess && result.document && (
                <div className="rounded-lg border border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/30 p-4">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <CheckCircle2 className="h-4 w-4 text-green-600" />
                      <SmallText className="font-medium text-green-700 dark:text-green-400">
                        {result.document.filename} processed successfully
                      </SmallText>
                    </div>
                    <Badge variant="secondary">{result.document.total_chunks} chunks</Badge>
                  </div>
                </div>
              )}

              {}
              {Object.keys(task.stages ?? {}).length > 0 && (
                <div className="rounded-lg border border-border p-4">
                  {STAGE_ORDER.map(name => {
                    const info = task.stages[name]
                    return info ? <StageIndicator key={name} name={name} info={info} /> : null
                  })}
                  {task.total_duration_ms != null && (
                    <div className="flex items-center justify-between pt-2 mt-2 border-t border-border">
                      <SmallText className="font-medium">Total</SmallText>
                      <SmallText className="font-medium">{formatMs(task.total_duration_ms)}</SmallText>
                    </div>
                  )}
                </div>
              )}

              {}
              {isSuccess && result.chunks && result.chunks.length > 0 && (
                <div className="rounded-lg border border-border">
                  <button
                    onClick={() => setChunkViewOpen(!chunkViewOpen)}
                    className="w-full flex items-center justify-between p-4 hover:bg-muted/50 transition-colors"
                  >
                    <div className="flex items-center gap-2">
                      <Eye className="h-4 w-4" />
                      <SmallText className="font-medium">
                        View Chunks ({totalChunks})
                      </SmallText>
                    </div>
                    {chunkViewOpen
                      ? <ChevronDown className="h-4 w-4" />
                      : <ChevronRight className="h-4 w-4" />}
                  </button>
                  {chunkViewOpen && (
                    <div className="border-t border-border">
                      <ScrollArea className="max-h-[500px]">
                        <div className="p-4">
                          <Stack spacing="sm">
                            {result.chunks.map((chunk, i) => (
                              <ChunkCard key={chunk.id ?? i} chunk={chunk} index={i} />
                            ))}
                          </Stack>
                        </div>
                      </ScrollArea>
                    </div>
                  )}
                </div>
              )}

              {}
              <Button onClick={resetState} variant="secondary" className="w-full">
                <RotateCcw className="h-4 w-4 mr-2" />
                Upload Another Document
              </Button>
            </div>
          )}
        </Stack>
      </CardContent>
    </Card>
  )
}