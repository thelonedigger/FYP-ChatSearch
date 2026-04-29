import { cn } from '@/lib/utils'
import type { JSX, ReactNode } from 'react'

type Variant = 'h1' | 'h2' | 'h3' | 'h4' | 'body' | 'small' | 'caption'

const styles: Record<Variant, string> = {
  h1: 'text-3xl font-semibold text-foreground',
  h2: 'text-2xl font-semibold text-foreground',
  h3: 'text-xl font-semibold text-foreground',
  h4: 'text-lg font-medium text-foreground',
  body: 'text-base text-foreground leading-relaxed',
  small: 'text-sm text-muted-foreground',
  caption: 'text-xs text-muted-foreground',
}

const tags: Record<Variant, keyof JSX.IntrinsicElements> = {
  h1: 'h1', h2: 'h2', h3: 'h3', h4: 'h4', body: 'p', small: 'p', caption: 'span'
}

export function Text({ variant = 'body', className, children }: { variant?: Variant; className?: string; children: ReactNode }) {
  const Tag = tags[variant]
  return <Tag className={cn(styles[variant], className)}>{children}</Tag>
}
export const Heading1 = (p: { children: ReactNode; className?: string }) => <Text variant="h1" {...p} />
export const Heading2 = (p: { children: ReactNode; className?: string }) => <Text variant="h2" {...p} />
export const Heading3 = (p: { children: ReactNode; className?: string }) => <Text variant="h3" {...p} />
export const Heading4 = (p: { children: ReactNode; className?: string }) => <Text variant="h4" {...p} />
export const BodyText = (p: { children: ReactNode; className?: string }) => <Text variant="body" {...p} />
export const SmallText = (p: { children: ReactNode; className?: string }) => <Text variant="small" {...p} />
export const Caption = (p: { children: ReactNode; className?: string }) => <Text variant="caption" {...p} />