import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { Card } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { User, Bot, Info } from 'lucide-react'
import { SmallText, Caption } from './Typography'
import { DebugPanel } from './DebugPanel'
import type { DebugInfo } from '@/types/api'

interface MessageBubbleProps {
  type: 'user' | 'assistant'
  children: ReactNode
  metadata?: {
    turn?: number
    intent?: string
  }
  attachments?: ReactNode
  suggestions?: string[]
  debug?: DebugInfo | null
}

export function MessageBubble({ type, children, metadata, attachments, suggestions, debug }: MessageBubbleProps) {
  const isUser = type === 'user'

  return (
    <div className="w-full space-y-3">
      <div className={cn("flex gap-3 w-full", isUser && "flex-row-reverse")}>
        <div className={cn(
          "flex h-8 w-8 shrink-0 items-center justify-center rounded-full",
          isUser ? "bg-primary text-primary-foreground" : "bg-secondary text-secondary-foreground"
        )}>
          {isUser ? <User className="h-4 w-4" /> : <Bot className="h-4 w-4" />}
        </div>

        <div className="flex-1 max-w-content space-y-2">
          <div className={cn("flex items-center gap-2", isUser && "flex-row-reverse")}>
            <SmallText className="font-medium">{isUser ? 'You' : 'Assistant'}</SmallText>
            {metadata && (
              <div className="flex items-center gap-1.5">
                {metadata.turn && (
                  <Badge variant="outline" className="text-xs px-1.5 py-0.5">
                    #{metadata.turn}
                  </Badge>
                )}
                {metadata.intent && (
                  <Badge variant="secondary" className="text-xs px-1.5 py-0.5">
                    {metadata.intent}
                  </Badge>
                )}
              </div>
            )}
            {!isUser && debug && <DebugPanel debug={debug} />}
          </div>
          
          <Card className={cn("p-4", isUser ? "bg-primary/5" : "bg-muted/30")}>
            {children}
          </Card>
          
          {suggestions?.length ? (
            <Card className="bg-muted/30 p-4">
              <div className="flex items-start gap-2 mb-2">
                <Info className="h-4 w-4 text-primary shrink-0 mt-0.5" />
                <SmallText className="font-medium">Suggestions:</SmallText>
              </div>
              <ul className="space-y-1 ml-6 list-disc">
                {suggestions.map((s, i) => (
                  <li key={i}>
                    <Caption>{s}</Caption>
                  </li>
                ))}
              </ul>
            </Card>
          ) : null}
        </div>
      </div>
      
      {attachments && <div className="ml-11">{attachments}</div>}
    </div>
  )
}