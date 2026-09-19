import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { Slot } from "radix-ui"

// Lyra Badge — 20px tall, 11px caps, square. The background is the tone at ~15%
// over the surface; the text and optional leading dot are the tone at full
// strength (`bg-current` on the dot); the border stays the standard hairline.
// This is the only place hue is allowed to mean something.
const badgeVariants = cva(
  "group/badge inline-flex h-5 w-fit shrink-0 items-center justify-center gap-1.5 rounded-sm border border-border px-2 text-[11px] leading-none font-medium tracking-[0.06em] uppercase whitespace-nowrap transition-colors duration-[120ms] focus-visible:ring-2 focus-visible:ring-ring/40 [&>svg]:pointer-events-none [&>svg]:size-2.5",
  {
    variants: {
      variant: {
        default: "bg-muted text-muted-foreground",
        success: "bg-success/15 text-success",
        warning: "bg-warning/15 text-warning",
        destructive: "bg-destructive/15 text-destructive",
        outline: "bg-transparent text-muted-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant = "default",
  dot = false,
  asChild = false,
  children,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean; dot?: boolean }) {
  const Comp = asChild ? Slot.Root : "span"

  return (
    <Comp
      data-slot="badge"
      data-variant={variant}
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    >
      {dot ? (
        <span
          aria-hidden
          className="size-1.5 shrink-0 rounded-full bg-current"
        />
      ) : null}
      {children}
    </Comp>
  )
}

export { Badge, badgeVariants }
