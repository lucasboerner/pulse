"use client"

import * as React from "react"
import { Progress as ProgressPrimitive } from "radix-ui"

import { cn } from "@/lib/utils"

// The indicator tone. Brand is the default slate-teal; warning and destructive
// let a meter carry its own alarm (e.g. an SLO budget running low).
const INDICATOR_TONE = {
  brand: "bg-primary",
  warning: "bg-warning",
  destructive: "bg-destructive",
} as const

type ProgressTone = keyof typeof INDICATOR_TONE

interface ProgressProps
  extends React.ComponentProps<typeof ProgressPrimitive.Root> {
  tone?: ProgressTone
}

// Lyra Progress — a full-width 8px track (bg-muted) with a rounded indicator that
// slides in from the left. The value is a 0–100 percentage; the tone colours the
// indicator without touching the track.
function Progress({ className, value, tone = "brand", ...props }: ProgressProps) {
  const clamped = Math.min(100, Math.max(0, value ?? 0))

  return (
    <ProgressPrimitive.Root
      data-slot="progress"
      value={clamped}
      className={cn(
        "relative h-2 w-full overflow-hidden rounded-full bg-muted",
        className,
      )}
      {...props}
    >
      <ProgressPrimitive.Indicator
        data-slot="progress-indicator"
        className={cn("h-full w-full flex-1 rounded-full transition-all", INDICATOR_TONE[tone])}
        style={{ transform: `translateX(-${100 - clamped}%)` }}
      />
    </ProgressPrimitive.Root>
  )
}

export { Progress }
export type { ProgressTone }
