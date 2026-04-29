
import { useState, useRef, useEffect, useCallback } from 'react'
import type { SourceReference } from '@/types/api'
import { FileText, X } from 'lucide-react'

interface SourceCitationProps {
  sourceIndex: number
  source?: SourceReference
}


let globalZIndex = 50

/**
 * Inline citation marker [n] with a popover showing source details.
 * Hovering previews the popover; clicking pins it open until dismissed
 * via the close button, clicking the badge again, or clicking outside.
 */
export function SourceCitation({ sourceIndex, source }: SourceCitationProps) {
  const [hovered, setHovered] = useState(false)
  const [pinned, setPinned] = useState(false)
  const [zIndex, setZIndex] = useState(globalZIndex)

  const containerRef = useRef<HTMLSpanElement>(null)
  const popoverRef = useRef<HTMLDivElement>(null)

  const isVisible = hovered || pinned

  const handlePin = useCallback(() => {
    setPinned(prev => {
      if (!prev) {
        globalZIndex += 1
        setZIndex(globalZIndex)
      }
      return !prev
    })
  }, [])

  const handleClose = useCallback(() => {
    setPinned(false)
  }, [])
  useEffect(() => {
    if (!pinned) return

    const handler = (e: MouseEvent) => {
      const target = e.target as Node
      if (
        containerRef.current && !containerRef.current.contains(target) &&
        popoverRef.current && !popoverRef.current.contains(target)
      ) {
        setPinned(false)
      }
    }

    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [pinned])

  return (
    <span className="relative inline-block" ref={containerRef}>
      <button
        type="button"
        onClick={handlePin}
        onMouseEnter={() => setHovered(true)}
        onMouseLeave={() => setHovered(false)}
        className="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 text-[10px] font-semibold rounded bg-primary/15 text-primary hover:bg-primary/25 transition-colors cursor-pointer align-super leading-none mx-0.5"
        aria-label={`Source ${sourceIndex}`}
      >
        {sourceIndex}
      </button>

      {isVisible && source && (
        <div
          ref={popoverRef}
          style={{ zIndex }}
          className="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-56 rounded-lg border border-border bg-white dark:bg-neutral-900 text-foreground shadow-lg p-2.5 space-y-1.5"
          onMouseEnter={() => setHovered(true)}
          onMouseLeave={() => setHovered(false)}
        >
          {}
          <div className="absolute top-full left-1/2 -translate-x-1/2 -mt-px">
            <div className="w-2.5 h-2.5 bg-white dark:bg-neutral-900 border-r border-b border-border rotate-45 -translate-y-1.5" />
          </div>

          {}
          {pinned && (
            <button
              type="button"
              onClick={handleClose}
              className="absolute top-1.5 right-1.5 rounded-sm p-0.5 text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
              aria-label="Close citation"
            >
              <X className="h-3 w-3" />
            </button>
          )}

          <div className="flex items-center gap-1.5">
            <div className="rounded bg-primary/10 p-1 shrink-0">
                <FileText className="h-3 w-3 text-primary" />
            </div>
            <p className="text-xs font-medium truncate">{source.filename}</p>
            {source.score != null && (
                <span className="text-[10px] font-medium text-muted-foreground shrink-0">
                {Math.round(source.score * 100)}%
                </span>
            )}
            {source.chunk_index != null && (
                <span className="text-[10px] text-muted-foreground shrink-0">
                #{source.chunk_index + 1}
                </span>
            )}
            </div>

          <p className="text-xs text-muted-foreground leading-relaxed line-clamp-4">
            {source.content}
          </p>
        </div>
      )}
    </span>
  )
}