
import { useState, useRef, useEffect } from 'react'
import { Bug, ChevronDown, ChevronRight, Clock, Cpu, FileText, X } from 'lucide-react'
import type { DebugInfo, LlmDebugCall } from '@/types/api'

interface DebugPanelProps {
  debug: DebugInfo
}

/**
 * Collapsible debug overlay for a single assistant message.
 * Shows raw LLM responses, prompts, and timing stats for both
 * the intent classifier and the answer generator.
 */
export function DebugPanel({ debug }: DebugPanelProps) {
  const [open, setOpen] = useState(false)
  const [openAbove, setOpenAbove] = useState(true)
  const panelRef = useRef<HTMLDivElement>(null)
  const buttonRef = useRef<HTMLButtonElement>(null)
  useEffect(() => {
    if (!open || !buttonRef.current) return

    const rect = buttonRef.current.getBoundingClientRect()
    const viewportHeight = window.innerHeight
    const panelMaxHeight = viewportHeight * 0.7 // matches max-h-[70vh]
    setOpenAbove(rect.top > panelMaxHeight)
  }, [open])

  useEffect(() => {
    if (!open) return
    const handler = (e: MouseEvent) => {
      if (panelRef.current && !panelRef.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  const hasIntent = debug.intent && (debug.intent.raw_response || debug.intent.prompt)
  const hasNlg = debug.nlg && (debug.nlg.raw_response || debug.nlg.prompt)

  if (!hasIntent && !hasNlg) return null

  return (
    <div className="relative inline-block" ref={panelRef}>
      <button
        ref={buttonRef}
        type="button"
        onClick={() => setOpen(prev => !prev)}
        className="inline-flex items-center justify-center h-6 w-6 rounded text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
        aria-label="Toggle debug info"
        title="Debug info"
      >
        <Bug className="h-3.5 w-3.5" />
      </button>

      {open && (
        <div className={`absolute ${openAbove ? 'bottom-full mb-2' : 'top-full mt-2'} right-0 w-[32rem] max-h-[70vh] overflow-y-auto rounded-lg border border-border bg-white dark:bg-neutral-900 shadow-xl z-[100] text-xs`}>
          <div className="sticky top-0 bg-white dark:bg-neutral-900 border-b border-border px-3 py-2 flex items-center justify-between">
            <span className="font-semibold text-sm flex items-center gap-1.5">
              <Bug className="h-4 w-4" /> LLM Debug
            </span>
            <button type="button" onClick={() => setOpen(false)} className="p-0.5 rounded hover:bg-muted">
              <X className="h-3.5 w-3.5" />
            </button>
          </div>

          <div className="p-3 space-y-3">
            {hasIntent && (
              <DebugSection title="Intent Classification" data={debug.intent!} />
            )}
            {hasNlg && (
              <DebugSection title="Answer Generation" data={debug.nlg!} />
            )}
            {debug.timing && <TimingSection timing={debug.timing} />}
          </div>
        </div>
      )}
    </div>
  )
}

function DebugSection({ title, data }: { title: string; data: LlmDebugCall }) {
  const [showPrompt, setShowPrompt] = useState(false)
  const [showResponse, setShowResponse] = useState(false)

  const timeMs = data.response_time_ms ?? data.llm_response_time_ms

  return (
    <div className="rounded border border-border overflow-hidden">
      <div className="bg-muted/50 px-3 py-1.5 font-semibold text-foreground flex items-center gap-1.5">
        <Cpu className="h-3 w-3" />
        {title}
      </div>
      <div className="px-3 py-2 space-y-2">
        {}
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-muted-foreground">
          {data.provider && <span><strong>Provider:</strong> {data.provider}</span>}
          {data.model && <span><strong>Model:</strong> {data.model}</span>}
          {timeMs != null && (
            <span className="flex items-center gap-0.5">
              <Clock className="h-3 w-3" />
              {timeMs.toFixed(0)}ms
            </span>
          )}
          {data.response_length != null && <span><strong>Length:</strong> {data.response_length} chars</span>}
          {data.usage && (
            <span>
              <strong>Tokens:</strong>{' '}
              {data.usage.prompt_tokens ?? '?'}→{data.usage.completion_tokens ?? '?'} ({data.usage.total_tokens ?? '?'})
            </span>
          )}
          {data.fallback && <span className="text-amber-600 font-semibold">⚠ Fallback</span>}
          {data.error && <span className="text-red-500 font-semibold">✗ {data.error}</span>}
        </div>

        {}
        {data.prompt && (
          <CollapsibleBlock
            label="Prompt Sent"
            open={showPrompt}
            onToggle={() => setShowPrompt(p => !p)}
            content={data.prompt}
          />
        )}

        {}
        {data.raw_response && (
          <CollapsibleBlock
            label="Raw Response"
            open={showResponse}
            onToggle={() => setShowResponse(p => !p)}
            content={data.raw_response}
          />
        )}
      </div>
    </div>
  )
}

function CollapsibleBlock({ label, open, onToggle, content }: {
  label: string
  open: boolean
  onToggle: () => void
  content: string
}) {
  return (
    <div>
      <button
        type="button"
        onClick={onToggle}
        className="flex items-center gap-1 text-primary hover:underline font-medium"
      >
        {open ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />}
        <FileText className="h-3 w-3" />
        {label}
      </button>
      {open && (
        <pre className="mt-1 p-2 rounded bg-muted text-[11px] leading-relaxed whitespace-pre-wrap break-words max-h-60 overflow-y-auto font-mono">
          {content}
        </pre>
      )}
    </div>
  )
}

function TimingSection({ timing }: { timing: Record<string, number> }) {
  return (
    <div className="rounded border border-border overflow-hidden">
      <div className="bg-muted/50 px-3 py-1.5 font-semibold text-foreground flex items-center gap-1.5">
        <Clock className="h-3 w-3" />
        Pipeline Timing
      </div>
      <div className="px-3 py-2 grid grid-cols-2 gap-x-4 gap-y-1 text-muted-foreground">
        {Object.entries(timing)
          .filter(([, v]) => v != null)
          .map(([key, value]) => (
            <div key={key} className="flex justify-between">
              <span>{formatTimingKey(key)}</span>
              <span className="font-mono text-foreground">{typeof value === 'number' ? `${value.toFixed(0)}ms` : value}</span>
            </div>
          ))}
      </div>
    </div>
  )
}


function formatTimingKey(key: string): string {
  return key
    .replace(/_ms$/i, '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, c => c.toUpperCase())
}