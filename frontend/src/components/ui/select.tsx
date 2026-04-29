import * as React from "react"
import * as S from "@radix-ui/react-select"
import { Check, ChevronDown } from "lucide-react"
import { cn } from "@/lib/utils"

export const Select = S.Root
export const SelectGroup = S.Group
export const SelectValue = S.Value

export const SelectTrigger = React.forwardRef<React.ComponentRef<typeof S.Trigger>, React.ComponentPropsWithoutRef<typeof S.Trigger>>(({ className, children, ...props }, ref) => (
  <S.Trigger ref={ref} className={cn("flex h-10 w-full items-center justify-between rounded-md border border-input bg-white px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50", className)} {...props}>
    {children}
    <S.Icon asChild><ChevronDown className="h-4 w-4 opacity-50" /></S.Icon>
  </S.Trigger>
))
SelectTrigger.displayName = "SelectTrigger"

export const SelectContent = React.forwardRef<React.ComponentRef<typeof S.Content>, React.ComponentPropsWithoutRef<typeof S.Content>>(({ className, children, position = "popper", ...props }, ref) => (
  <S.Portal>
    <S.Content ref={ref} className={cn("relative z-50 min-w-[8rem] overflow-hidden rounded-md border border-border bg-white text-foreground shadow-md", position === "popper" && "data-[side=bottom]:translate-y-1 data-[side=left]:-translate-x-1 data-[side=right]:translate-x-1 data-[side=top]:-translate-y-1", className)} position={position} {...props}>
      <S.Viewport className={cn("p-1", position === "popper" && "h-[var(--radix-select-trigger-height)] w-full min-w-[var(--radix-select-trigger-width)]")}>{children}</S.Viewport>
    </S.Content>
  </S.Portal>
))
SelectContent.displayName = "SelectContent"

export const SelectItem = React.forwardRef<React.ComponentRef<typeof S.Item>, React.ComponentPropsWithoutRef<typeof S.Item>>(({ className, children, ...props }, ref) => (
  <S.Item ref={ref} className={cn("relative flex w-full cursor-default select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50", className)} {...props}>
    <span className="absolute left-2 flex h-3.5 w-3.5 items-center justify-center"><S.ItemIndicator><Check className="h-4 w-4" /></S.ItemIndicator></span>
    <S.ItemText>{children}</S.ItemText>
  </S.Item>
))
SelectItem.displayName = "SelectItem"