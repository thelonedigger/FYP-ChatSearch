
import { useState } from 'react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Heading4, Caption } from '@/components/common/Typography'
import { formatters } from '@/lib/formatters'
import { MessageSquare, Plus, MoreVertical, Trash2, ChevronLeft, ChevronRight } from 'lucide-react'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu"

interface SidebarProps { 
  currentConversationId?: number
  onSelectConversation: (id: number) => void
  onNewConversation: () => void
  conversations: Array<{ id: number; title: string; lastMessage: string; timestamp: string; messageCount: number }>
  onDeleteConversation: (id: number) => void
}

export function Sidebar({ 
  currentConversationId, 
  onSelectConversation, 
  onNewConversation,
  conversations,
  onDeleteConversation
}: SidebarProps) {
  const [collapsed, setCollapsed] = useState(false)

  if (collapsed) return (
    <div className="w-16 border-r border-sidebar-border bg-sidebar flex flex-col items-center py-4 gap-4">
      <Button variant="ghost" size="icon" onClick={() => setCollapsed(false)}><ChevronRight className="h-5 w-5" /></Button>
      <Button variant="ghost" size="icon" onClick={onNewConversation}><Plus className="h-5 w-5" /></Button>
    </div>
  )

  return (
    <div className="w-sidebar border-r border-sidebar-border bg-sidebar flex flex-col">
      <div className="p-4 border-b border-sidebar-border flex items-center justify-between">
        <Heading4>Conversations</Heading4>
        <div className="flex gap-1">
          <Button variant="ghost" size="icon" onClick={onNewConversation}><Plus className="h-5 w-5" /></Button>
          <Button variant="ghost" size="icon" onClick={() => setCollapsed(true)}><ChevronLeft className="h-5 w-5" /></Button>
        </div>
      </div>
      <ScrollArea className="flex-1">
        <div className="p-2 space-y-1 overflow-hidden">
          {!conversations.length ? (
            <div className="p-4 text-center"><Caption>No conversations yet</Caption></div>
          ) : conversations.map(c => (
            <div 
              key={c.id} 
              className={cn(
                "group flex items-center gap-2 rounded-lg px-3 py-2.5 cursor-pointer transition-colors",
                c.id === currentConversationId ? "bg-accent" : "hover:bg-accent/50"
              )} 
              onClick={() => onSelectConversation(c.id)}
            >
              <MessageSquare className="h-4 w-4 shrink-0 text-muted-foreground" />
              
              <div className="flex-1 min-w-0 overflow-hidden">
                <p className="text-sm font-medium text-foreground truncate">
                  {c.title}
                </p>
                <div className="flex items-center gap-2 mt-0.5">
                  <Caption className="truncate">{formatters.relativeTime(c.timestamp)}</Caption>
                  {c.messageCount > 0 && (
                    <>
                      <span className="text-muted-foreground shrink-0">•</span>
                      <Caption className="shrink-0">{c.messageCount} messages</Caption>
                    </>
                  )}
                </div>
              </div>

              <DropdownMenu>
                <DropdownMenuTrigger asChild onClick={e => e.stopPropagation()}>
                  <Button 
                    variant="ghost" 
                    size="icon" 
                    className="h-8 w-8 shrink-0 opacity-60 group-hover:opacity-100 hover:opacity-100 transition-opacity ml-1"
                  >
                    <MoreVertical className="h-4 w-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent 
                  align="end" 
                  onClick={e => e.stopPropagation()}
                  className="bg-background border border-border shadow-lg backdrop-blur-sm"
                >
                  <DropdownMenuItem 
                    onClick={e => { 
                      e.stopPropagation()
                      if (confirm(`Delete "${c.title}"?`)) {
                        onDeleteConversation(c.id)
                      }
                    }}
                    className="text-destructive focus:text-destructive focus:bg-destructive/10"
                  >
                    <Trash2 className="h-4 w-4 mr-2" />
                    Delete
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          ))}
        </div>
      </ScrollArea>
    </div>
  )
}