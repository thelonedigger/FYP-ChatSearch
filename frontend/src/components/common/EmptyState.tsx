import { cn } from '@/lib/utils'
import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'

export function EmptyState({ icon: Icon, title, description, action, className }: { icon: LucideIcon; title: string; description: string; action?: ReactNode; className?: string }) {
  return (
    <div className={cn('flex flex-col items-center justify-center text-center p-12', className)}>
      <div className="rounded-full bg-muted p-6 mb-4"><Icon className="h-8 w-8 text-muted-foreground" /></div>
      <h3 className="text-xl font-semibold text-foreground mb-2">{title}</h3>
      <p className="text-base text-muted-foreground max-w-md mb-6">{description}</p>
      {action && <div>{action}</div>}
    </div>
  )
}