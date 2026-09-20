"use client";

import { useState } from "react";

import { cn } from "@/lib/utils";
import type { DailyStatus } from "@/features/monitor/types";
import { formatDayLabel } from "@/features/monitor/lib/status";
import { ChartTooltip } from "@/features/monitor/components/chart-tooltip";

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

const STATUS_LABEL: Record<NonNullable<DailyStatus["status"]>, string> = {
  up: "Up",
  degraded: "Degraded",
  down: "Down",
};

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
// from assistive tech, and hovering a day floats its date and status above the row.
//
// Each bar sits in a full-height hover target, so a 6px no-data stub is as easy to
// hit as a tall one, and a single ChartTooltip serves the whole strip rather than a
// tooltip root per bar — the overview renders 45 bars per monitor row.
export function StatusBarStrip({ days, heights, className }: StatusBarStripProps) {
  const [hovered, setHovered] = useState<number | null>(null);

  const list = days ?? [];
  const rowHeight = Math.max(heights.up, heights.alert, heights.none);
  const active = hovered == null ? null : (list[hovered] ?? null);

  return (
    <div className={cn("relative", className)}>
      {active && hovered != null && (
        <ChartTooltip
          at={(hovered + 0.5) / list.length}
          label={formatDayLabel(active.day)}
          value={active.status ? STATUS_LABEL[active.status] : "No data"}
        />
      )}

      <div
        aria-hidden
        className="flex items-end gap-0.5"
        style={{ height: rowHeight }}
        onPointerLeave={() => setHovered(null)}
      >
        {list.map((day, index) => {
          const meta = barMeta(day.status, heights);
          return (
            <span
              key={`${day.day}-${index}`}
              className="flex h-full min-w-0 flex-1 items-end"
              onPointerEnter={() => setHovered(index)}
            >
              <span
                className={cn(
                  "w-full rounded-[2px] transition-opacity duration-[120ms]",
                  meta.className,
                  hovered != null && hovered !== index && "opacity-50",
                )}
                style={{ height: meta.height }}
              />
            </span>
          );
        })}
      </div>
    </div>
  );
}
