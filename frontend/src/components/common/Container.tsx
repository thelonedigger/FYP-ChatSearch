import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

const maxWidths = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl', content: 'max-w-content', full: 'max-w-full' }
const spacings = { sm: 'space-y-2', md: 'space-y-4', lg: 'space-y-6' }

export const Container = ({ children, className, maxWidth = 'full' }: { children: ReactNode; className?: string; maxWidth?: keyof typeof maxWidths }) => (
  <div className={cn('mx-auto px-4', maxWidths[maxWidth], className)}>{children}</div>
)

export const Box = ({ children, className }: { children: ReactNode; className?: string }) => (
  <div className={cn('space-y-4', className)}>{children}</div>
)

export const Stack = ({ children, className, spacing = 'md' }: { children: ReactNode; className?: string; spacing?: keyof typeof spacings }) => (
  <div className={cn('flex flex-col', spacings[spacing], className)}>{children}</div>
)

export const Divider = ({ className }: { className?: string }) => <hr className={cn('border-border', className)} />