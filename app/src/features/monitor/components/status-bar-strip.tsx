import { cn } from "@/lib/utils";
import type { DailyStatus } from "@/features/monitor/types";

interface BarHeights {
  /** Height of an up day. */
  up: number;
  /** Height of a down or degraded day — the alert bar sticks up above the rest. */
  alert: number;
  /** Height of a day with no check (a short grey stub). */
  none: number;
}

interface StatusBarStripProps {
  days: DailyStatus[];
  /** Pixel heights per state; bars align to a shared baseline, so alert bars rise. */
  heights: BarHeights;
  className?: string;
}

interface BarMeta {
  height: number;
  className: string;
}

// Status hue lives in the bar fill, keyed off the day's worst status. A null day
// (no check) reads as a short border-coloured stub, never an alarm.
function barMeta(status: DailyStatus["status"], heights: BarHeights): BarMeta {
  switch (status) {
    case "up":
      return { height: heights.up, className: "bg-success" };
    case "degraded":
      return { height: heights.alert, className: "bg-warning" };
    case "down":
      return { height: heights.alert, className: "bg-destructive" };
    default:
      return { height: heights.none, className: "bg-border" };
  }
}

// A row of daily status bars: each flex-1, 2px gaps, 2px rounded, aligned to a
// shared baseline so a down/degraded day rises above the up days. Decorative — the
// day's status is also carried by the row's dot and badge — so the strip is hidden
// from assistive tech, with a per-bar title for a hover read.
export function StatusBarStrip({ days, heights, className }: StatusBarStripProps) {
  return (
    <div aria-hidden className={cn("flex items-end gap-0.5", className)}>
      {(days ?? []).map((day, index) => {
        const meta = barMeta(day.status, heights);
        return (
          <span
            key={`${day.day}-${index}`}
            title={`${day.day} — ${day.status ?? "no data"}`}
            className={cn("min-w-0 flex-1 rounded-[2px]", meta.className)}
            style={{ height: meta.height }}
          />
        );
      })}
    </div>
  );
}
