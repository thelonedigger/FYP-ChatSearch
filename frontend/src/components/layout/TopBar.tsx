import { MessageSquare, FileText, Database, LogOut, BarChart3, Settings, MessagesSquare } from 'lucide-react'
import { StatCard } from '@/components/common/StatCard'
import { StatsSkeleton } from '@/components/common/LoadingState'
import { Button } from '@/components/ui/button'
import { formatters } from '@/lib/formatters'
import { useStats } from '@/hooks'
import { cn } from '@/lib/utils'

type ActiveView = 'chat' | 'analytics' | 'admin'

interface TopBarProps {
  activeView: ActiveView
  onChangeView: (view: ActiveView) => void
  userName?: string
  onLogout: () => void
  conversationCount: number
}

const TABS: { key: ActiveView; label: string; icon: typeof MessageSquare }[] = [
  { key: 'chat', label: 'Chat', icon: MessagesSquare },
  { key: 'analytics', label: 'Analytics', icon: BarChart3 },
  { key: 'admin', label: 'Admin', icon: Settings },
]

export function TopBar({ activeView, onChangeView, userName, onLogout, conversationCount }: TopBarProps) {
  const { stats, loading, error } = useStats()
  const entities = stats?.entities || {}
  const totalEntities = Object.values(entities).reduce((s, e) => s + e.total, 0)
  const totalChunks = Object.values(entities).reduce((s, e) => s + e.total_chunks, 0)

  return (
    <div className="h-auto min-h-topbar border-b border-border bg-background px-4 py-3 md:px-6">
      <div className="flex flex-wrap items-center gap-3 md:gap-4">
        {loading ? <div className="flex gap-3 md:gap-4 flex-1 min-w-0"><StatsSkeleton /></div> : error ? (
          <div className="flex-1 text-sm text-red-500 min-w-0">Failed to load stats</div>
        ) : (
          <>
            <div className="hidden md:block"><StatCard icon={MessageSquare} label="Conversations" value={formatters.compactNumber(conversationCount)} /></div>
            <StatCard icon={FileText} label="Documents" value={formatters.compactNumber(entities.document?.total || 0)} />
            <div className="hidden lg:block"><StatCard icon={Database} label="Total Chunks" value={formatters.compactNumber(totalChunks)} /></div>
          </>
        )}
        <div className="flex-1 min-w-[2rem]" />

        {}
        <div className="flex items-center rounded-lg border border-border bg-muted/50 p-1">
          {TABS.map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              onClick={() => onChangeView(key)}
              className={cn(
                'flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                activeView === key
                  ? 'bg-background text-foreground shadow-sm'
                  : 'text-muted-foreground hover:text-foreground'
              )}
            >
              <Icon className="h-4 w-4" />
              <span className="hidden sm:inline">{label}</span>
            </button>
          ))}
        </div>

        {userName && (
          <div className="flex items-center gap-2 ml-2 pl-2 border-l border-border">
            <span className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-xs font-medium text-primary">
              {userName.slice(-1)}
            </span>
            <span className="hidden sm:inline text-sm text-muted-foreground">{userName}</span>
            <Button variant="ghost" size="icon" onClick={onLogout} className="shrink-0 h-8 w-8">
              <LogOut className="h-4 w-4" />
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}