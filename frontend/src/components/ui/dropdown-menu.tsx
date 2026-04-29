import * as React from "react"
import * as D from "@radix-ui/react-dropdown-menu"
import { Check, ChevronRight, Circle } from "lucide-react"
import { cn } from "@/lib/utils"

export const DropdownMenu = D.Root
export const DropdownMenuTrigger = D.Trigger
export const DropdownMenuGroup = D.Group
export const DropdownMenuPortal = D.Portal
export const DropdownMenuSub = D.Sub
export const DropdownMenuRadioGroup = D.RadioGroup

export const DropdownMenuSubTrigger = React.forwardRef<React.ComponentRef<typeof D.SubTrigger>, React.ComponentPropsWithoutRef<typeof D.SubTrigger> & { inset?: boolean }>(({ className, inset, children, ...props }, ref) => (
  <D.SubTrigger ref={ref} className={cn("flex cursor-default select-none items-center rounded-sm px-2 py-1.5 text-sm outline-none focus:bg-accent data-[state=open]:bg-accent", inset && "pl-8", className)} {...props}>{children}<ChevronRight className="ml-auto h-4 w-4" /></D.SubTrigger>
))
DropdownMenuSubTrigger.displayName = "DropdownMenuSubTrigger"

export const DropdownMenuSubContent = React.forwardRef<React.ComponentRef<typeof D.SubContent>, React.ComponentPropsWithoutRef<typeof D.SubContent>>(({ className, ...props }, ref) => (
  <D.SubContent ref={ref} className={cn("z-50 min-w-[8rem] overflow-hidden rounded-md border bg-popover p-1 text-popover-foreground shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2", className)} {...props} />
))
DropdownMenuSubContent.displayName = "DropdownMenuSubContent"

export const DropdownMenuContent = React.forwardRef<React.ComponentRef<typeof D.Content>, React.ComponentPropsWithoutRef<typeof D.Content>>(({ className, sideOffset = 4, ...props }, ref) => (
  <D.Portal><D.Content ref={ref} sideOffset={sideOffset} className={cn("z-50 min-w-[8rem] overflow-hidden rounded-md border bg-popover p-1 text-popover-foreground shadow-md data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2", className)} {...props} /></D.Portal>
))
DropdownMenuContent.displayName = "DropdownMenuContent"

export const DropdownMenuItem = React.forwardRef<React.ComponentRef<typeof D.Item>, React.ComponentPropsWithoutRef<typeof D.Item> & { inset?: boolean }>(({ className, inset, ...props }, ref) => (
  <D.Item ref={ref} className={cn("relative flex cursor-default select-none items-center rounded-sm px-2 py-1.5 text-sm outline-none transition-colors focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50", inset && "pl-8", className)} {...props} />
))
DropdownMenuItem.displayName = "DropdownMenuItem"

export const DropdownMenuCheckboxItem = React.forwardRef<React.ComponentRef<typeof D.CheckboxItem>, React.ComponentPropsWithoutRef<typeof D.CheckboxItem>>(({ className, children, checked, ...props }, ref) => (
  <D.CheckboxItem ref={ref} className={cn("relative flex cursor-default select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none transition-colors focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50", className)} checked={checked} {...props}>
    <span className="absolute left-2 flex h-3.5 w-3.5 items-center justify-center"><D.ItemIndicator><Check className="h-4 w-4" /></D.ItemIndicator></span>{children}
  </D.CheckboxItem>
))
DropdownMenuCheckboxItem.displayName = "DropdownMenuCheckboxItem"

export const DropdownMenuRadioItem = React.forwardRef<React.ComponentRef<typeof D.RadioItem>, React.ComponentPropsWithoutRef<typeof D.RadioItem>>(({ className, children, ...props }, ref) => (
  <D.RadioItem ref={ref} className={cn("relative flex cursor-default select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none transition-colors focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50", className)} {...props}>
    <span className="absolute left-2 flex h-3.5 w-3.5 items-center justify-center"><D.ItemIndicator><Circle className="h-2 w-2 fill-current" /></D.ItemIndicator></span>{children}
  </D.RadioItem>
))
DropdownMenuRadioItem.displayName = "DropdownMenuRadioItem"

export const DropdownMenuLabel = React.forwardRef<React.ComponentRef<typeof D.Label>, React.ComponentPropsWithoutRef<typeof D.Label> & { inset?: boolean }>(({ className, inset, ...props }, ref) => (
  <D.Label ref={ref} className={cn("px-2 py-1.5 text-sm font-semibold", inset && "pl-8", className)} {...props} />
))
DropdownMenuLabel.displayName = "DropdownMenuLabel"

export const DropdownMenuSeparator = React.forwardRef<React.ComponentRef<typeof D.Separator>, React.ComponentPropsWithoutRef<typeof D.Separator>>(({ className, ...props }, ref) => (
  <D.Separator ref={ref} className={cn("-mx-1 my-1 h-px bg-muted", className)} {...props} />
))
DropdownMenuSeparator.displayName = "DropdownMenuSeparator"

export const DropdownMenuShortcut = ({ className, ...props }: React.HTMLAttributes<HTMLSpanElement>) => (
  <span className={cn("ml-auto text-xs tracking-widest opacity-60", className)} {...props} />
)