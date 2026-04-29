import { useState } from 'react'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { FileText, ChevronDown, ChevronUp, Search } from 'lucide-react'
import { Heading4, SmallText, Caption } from '@/components/common/Typography'
import { Stack } from '@/components/common/Container'
import { formatters } from '@/lib/formatters'
import type { TextChunk, DocumentResult } from '@/types/api'
function getScoreLabel(r: TextChunk): string {
  if (r.fusion_score != null) return 'Hybrid'
  if (r.similarity != null) return 'Vector'
  if (r.trigram_similarity != null) return 'Text'
  if (r.relevance_score != null) return 'Match'
  return 'Score'
}

export function ResultCard({ result: r, rank }: { result: TextChunk; rank: number }) {
  const score = r.fusion_score ?? r.similarity ?? r.trigram_similarity ?? r.relevance_score

  return (
    <Card className="overflow-hidden hover:shadow-md transition-shadow">
      <div className="p-4 space-y-3">
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-start gap-3 flex-1 min-w-0">
            <div className="rounded-md bg-primary/10 p-2 shrink-0"><FileText className="h-4 w-4 text-primary" /></div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2">
                <Badge variant="outline" className="text-xs px-1.5 py-0.5 shrink-0">#{rank}</Badge>
                <SmallText className="font-medium truncate">{r.document.filename}</SmallText>
              </div>
              <Caption className="mt-0.5">Chunk {r.chunk_index + 1} • {r.word_count} words</Caption>
            </div>
          </div>
          {score != null && (
            <div className="flex flex-col items-end gap-1 shrink-0">
              <Caption className="uppercase font-medium">{getScoreLabel(r)}</Caption>
              <Badge variant="secondary" className="text-sm font-semibold px-2 py-1">{formatters.percentage(score)}</Badge>
            </div>
          )}
        </div>
        <div
          className="rounded bg-muted/60 px-3 py-2 text-sm leading-relaxed text-foreground/85"
          dangerouslySetInnerHTML={{ __html: r.highlighted_content || r.content }}
        />
        <div className="flex items-center gap-2 flex-wrap">
          {r.search_strategy && <Badge variant="outline" className="text-xs">{formatters.intentLabel(r.search_strategy)}</Badge>}
          {r.ranking_details && r.ranking_details.length > 1 && <Badge variant="muted" className="text-xs">Hybrid ({r.ranking_details.length} methods)</Badge>}
        </div>
      </div>
    </Card>
  )
}
export function DocumentCard({ document: d, rank }: { document: DocumentResult; rank: number }) {
  const [showPreview, setShowPreview] = useState(false)
  const hasPreview = (d.preview_chunks?.length ?? 0) > 0

  return (
    <Card className="overflow-hidden hover:shadow-md transition-shadow">
      <div className="p-4 space-y-3">
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-start gap-3 flex-1 min-w-0">
            <div className="rounded-md bg-primary/10 p-2 shrink-0"><FileText className="h-4 w-4 text-primary" /></div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2">
                <Badge variant="outline" className="text-xs px-1.5 py-0.5 shrink-0">#{rank}</Badge>
                <SmallText className="font-medium truncate">{d.filename}</SmallText>
              </div>
              <div className="flex items-center gap-2 mt-1 flex-wrap">
                <Caption>{formatters.fileSize(d.file_size)}</Caption>
                <span className="text-muted-foreground">•</span>
                <Caption>{d.total_chunks} chunks</Caption>
                {d.created_at && <><span className="text-muted-foreground">•</span><Caption>{formatters.relativeTime(d.created_at)}</Caption></>}
              </div>
            </div>
          </div>
          {d.relevance_score != null && (
            <div className="flex flex-col items-end gap-1 shrink-0">
              <Caption className="uppercase font-medium">Match</Caption>
              <Badge variant="secondary" className="text-sm font-semibold px-2 py-1">{formatters.percentage(d.relevance_score)}</Badge>
            </div>
          )}
        </div>
        {hasPreview && (
          <>
            <Button variant="ghost" size="sm" onClick={() => setShowPreview(!showPreview)} className="w-full justify-between">
              <span className="text-xs">Preview content</span>
              {showPreview ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
            </Button>
            {showPreview && (
              <div className="space-y-2 pt-2 border-t border-border">
                {d.preview_chunks!.map(c => (
                  <div key={c.id} className="rounded bg-muted/60 px-3 py-2 text-sm text-foreground/85 leading-relaxed">
                    <Caption className="mb-1 font-medium">Chunk {c.chunk_index + 1}</Caption>
                    <div>{formatters.truncate(c.content, 200)}</div>
                  </div>
                ))}
              </div>
            )}
          </>
        )}
      </div>
    </Card>
  )
}
export function ResultsContainer({ type, results, title, searchType, isRefinement, originalCount }: {
  type: 'chunks' | 'documents'; results: TextChunk[] | DocumentResult[]; title?: string; searchType?: string; isRefinement?: boolean; originalCount?: number
}) {
  const [expanded, setExpanded] = useState(false)
  if (!results.length) return null

  return (
    <div className="rounded-lg bg-muted/40 p-4 space-y-3">
      <div className="flex items-center gap-2">
        <div className="flex items-center gap-2">
          <div className="rounded-md bg-primary/15 p-1.5"><Search className="h-4 w-4 text-primary" /></div>
          <Heading4 className="text-base">{title || (type === 'chunks' ? 'Search Results' : 'Documents')}</Heading4>
          <Badge variant="secondary" className="text-xs">{results.length}</Badge>
          {searchType && <Badge variant="outline" className="text-xs">{searchType}</Badge>}
          {isRefinement && originalCount && <Badge variant="muted" className="text-xs">Refined from {originalCount}</Badge>}
        </div>
        <Button variant="ghost" size="sm" onClick={() => setExpanded(!expanded)} className="gap-1.5">
          <span className="text-xs">{expanded ? 'Collapse' : 'Expand'}</span>
          {expanded ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
        </Button>
      </div>
      {expanded && (
        <Stack spacing="sm">
          {type === 'chunks'
            ? (results as TextChunk[]).map((r, i) => <ResultCard key={r.id} result={r} rank={i + 1} />)
            : (results as DocumentResult[]).map((d, i) => <DocumentCard key={d.id} document={d} rank={i + 1} />)}
        </Stack>
      )}
    </div>
  )
}