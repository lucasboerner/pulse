"use client";

import { cn } from "@/lib/utils";

interface ChartTooltipProps {
  /** Where the tooltip points, as a 0–1 fraction of the parent's width. */
  at: number;
  /** The small uppercase line — a day or a clock time. */
  label: string;
  /** The reading itself — a status word or a latency. */
  value: string;
  className?: string;
}

// The hover bubble shared by the status strip and the response chart: one absolutely
// positioned node per chart rather than a tooltip root per bar, so a 45-bar overview
// row stays cheap. It sits above its parent's top edge, centred on the anchor and
// flipped to one side near the edges so the first and last bars keep it inside the
// card. Pointer events are off — the strip lives inside a row-wide link that must
// stay clickable — and it is hidden from assistive tech, like the charts it serves.
export function ChartTooltip({ at, label, value, className }: ChartTooltipProps) {
  const anchor = at < 0.08 ? "translateX(0)" : at > 0.92 ? "translateX(-100%)" : "translateX(-50%)";

  return (
    <div
      aria-hidden
      className={cn(
        "pointer-events-none absolute bottom-full z-50 mb-1.5 flex w-max flex-col gap-0.5 rounded-md bg-foreground px-2.5 py-1.5 text-background shadow-sm",
        className,
      )}
      style={{ left: `${at * 100}%`, transform: anchor }}
    >
      <span className="font-mono text-[10px] tracking-[0.08em] uppercase opacity-70">{label}</span>
      <span className="text-[12px] font-medium">{value}</span>
    </div>
  );
}
