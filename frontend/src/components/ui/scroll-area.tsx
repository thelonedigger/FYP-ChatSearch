import * as React from "react"
import * as S from "@radix-ui/react-scroll-area"
import { cn } from "@/lib/utils"

const ScrollBar = React.forwardRef<React.ComponentRef<typeof S.ScrollAreaScrollbar>, React.ComponentPropsWithoutRef<typeof S.ScrollAreaScrollbar>>(
  ({ className, orientation = "vertical", ...props }, ref) => (
    <S.ScrollAreaScrollbar ref={ref} orientation={orientation} className={cn("flex touch-none select-none transition-colors", orientation === "vertical" && "h-full w-2.5 border-l border-l-transparent p-[1px]", orientation === "horizontal" && "h-2.5 border-t border-t-transparent p-[1px]", className)} {...props}>
      <S.ScrollAreaThumb className="relative flex-1 rounded-full bg-border" />
    </S.ScrollAreaScrollbar>
  )
)
ScrollBar.displayName = "ScrollBar"

const ScrollArea = React.forwardRef<React.ComponentRef<typeof S.Root>, React.ComponentPropsWithoutRef<typeof S.Root>>(({ className, children, ...props }, ref) => (
  <S.Root ref={ref} className={cn("relative overflow-hidden", className)} {...props}>
    <S.Viewport className="h-full w-full rounded-[inherit] [&>div]:!block">{children}</S.Viewport>
    <ScrollBar />
    <S.Corner />
  </S.Root>
))
ScrollArea.displayName = "ScrollArea"

export { ScrollArea, ScrollBar }