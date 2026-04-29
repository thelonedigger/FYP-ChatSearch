import { Skeleton } from '@/components/ui/skeleton'
import { Loader2 } from 'lucide-react'

export const MessageSkeleton = () => (
  <div className="flex gap-3">
    <Skeleton className="h-8 w-8 rounded-full shrink-0" />
    <div className="flex-1 max-w-content space-y-2"><Skeleton className="h-4 w-24" /><Skeleton className="h-32 w-full rounded-lg" /></div>
  </div>
)

export const StatusIndicator = ({ phase }: { phase: string }) => (
  <div className="flex items-center gap-2 text-muted-foreground text-sm animate-in fade-in duration-300">
    <Loader2 className="h-3.5 w-3.5 animate-spin" />
    <span>{phase}…</span>
  </div>
)

export const StatsSkeleton = () => (
  <div className="flex gap-4">{[1, 2, 3].map(i => <Skeleton key={i} className="h-20 w-48 rounded-lg" />)}</div>
)

export const ConversationListSkeleton = () => (
  <div className="flex flex-col space-y-2">{[1, 2, 3, 4, 5].map(i => <Skeleton key={i} className="h-16 w-full rounded-lg" />)}</div>
)