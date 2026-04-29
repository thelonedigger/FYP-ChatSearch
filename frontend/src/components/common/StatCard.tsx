import { cn } from '@/lib/utils'
import type { LucideIcon } from 'lucide-react'

export function StatCard({ label, value, icon: Icon, trend, className }: { label: string; value: string | number; icon?: LucideIcon; trend?: { value: string; direction: 'up' | 'down' | 'neutral' }; className?: string }) {
  return (
    <div className={cn('flex items-center gap-3 rounded-lg border border-border bg-card p-4', className)}>
      {Icon && <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-primary/10"><Icon className="h-5 w-5 text-primary" /></div>}
      <div className="flex-1 min-w-0">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="text-2xl font-semibold text-foreground">{value}</p>
        {trend && <p className={cn('text-xs mt-1', trend.direction === 'up' && 'text-green-600', trend.direction === 'down' && 'text-red-600', trend.direction === 'neutral' && 'text-muted-foreground')}>{trend.value}</p>}
      </div>
    </div>
  )
}