import type { FormEvent, KeyboardEvent } from 'react'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { MessageBubble } from '@/components/common/MessageBubble'
import { MessageSkeleton, StatusIndicator } from '@/components/common/LoadingState'
import { EmptyState } from '@/components/common/EmptyState'
import { Stack } from '@/components/common/Container'
import { ResultsContainer } from '@/components/common/SearchResults'
import { Send, MessageSquare } from 'lucide-react'
import { formatters } from '@/lib/formatters'
import type { Message } from '@/types/api'
import { MarkdownContent } from '@/components/common/MarkdownContent'

interface ConversationPanelProps { 
  messages: Message[]
  loading: boolean
  query: string
  onQueryChange: (v: string) => void
  onSubmit: (e: FormEvent) => void
  streamStatus?: string | null
}

export function ConversationPanel({ messages, loading, query, onQueryChange, onSubmit, streamStatus }: ConversationPanelProps) {
  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && !e.shiftKey && query.trim() && !loading) { e.preventDefault(); onSubmit(e as any) }
  }

  return (
    <div className="flex flex-col h-full">
      <ScrollArea className="flex-1 px-6 py-6">
        {!messages.length ? (
          <EmptyState icon={MessageSquare} title="Start a Conversation" description="Ask a question or search for content. The assistant will help you find what you need." />
        ) : (
          <Stack spacing="lg" className="max-w-4xl mx-auto">
            {messages.map((m, i) => (
              <MessageBubble key={i} type={m.type} metadata={m.metadata && { turn: m.metadata.turn, intent: m.metadata.intent ? formatters.intentLabel(m.metadata.intent) : undefined }}
                suggestions={m.searchResponse?.type === 'clarification_needed' ? m.searchResponse.suggestions : undefined}
                debug={m.type === 'assistant' ? m.debug : undefined}
                attachments={m.searchResponse && m.type === 'assistant' ? <>
                  {m.searchResponse.resources?.length ? <ResultsContainer type="chunks" results={m.searchResponse.resources} searchType={m.searchResponse.search_type} /> : null}
                  {m.searchResponse.documents?.length ? <ResultsContainer type="documents" results={m.searchResponse.documents} isRefinement={m.searchResponse.is_refinement} originalCount={m.searchResponse.original_count} /> : null}
                </> : null}>
                  {m.type === 'assistant' ? (
                    <MarkdownContent
                      content={m.content}
                      className="text-sm leading-relaxed"
                      sources={m.searchResponse?.sources}
                      trailing={
                        loading && i === messages.length - 1 ? (
                          <span className="inline-block w-1.5 h-4 ml-0.5 bg-foreground/70 animate-pulse align-text-bottom" />
                        ) : null
                      }
                    />
                  ) : (
                    <div className="text-sm leading-relaxed whitespace-pre-wrap">
                      {m.content}
                    </div>
                  )}
              </MessageBubble>
            ))}
            {loading && (
              streamStatus
                ? <StatusIndicator phase={streamStatus} />
                : !messages.length || messages[messages.length - 1]?.type === 'user'
                  ? <MessageSkeleton />
                  : null
            )}
          </Stack>
        )}
      </ScrollArea>
      <div className="border-t border-border bg-background p-4">
        <form onSubmit={onSubmit} className="max-w-4xl mx-auto">
          <div className="flex gap-2">
            <Input value={query} onChange={e => onQueryChange(e.target.value)} onKeyDown={handleKeyDown} placeholder="Ask a question or search for content..." disabled={loading} className="flex-1 h-12" />
            <Button type="submit" disabled={loading || !query.trim()} size="lg" className="px-6"><Send className="h-4 w-4" /></Button>
          </div>
        </form>
      </div>
    </div>
  )
}