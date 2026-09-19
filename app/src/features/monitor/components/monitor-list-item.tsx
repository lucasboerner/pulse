import Link from "next/link";

import { cn } from "@/lib/utils";
import type { MonitorRollup } from "@/features/monitor/types";
import { displayStatus, hostFromUrl } from "@/features/monitor/lib/status";
import { StatusDot } from "@/features/monitor/components/status-dot";
import { StatusBarStrip } from "@/features/monitor/components/status-bar-strip";
import { MonitorStatusBadge } from "@/features/monitor/components/monitor-status-badge";

interface MonitorListItemProps {
  monitor: MonitorRollup;
}

// One "All Systems" row: status dot, name over host, then the 30-day average
// response and uptime, the status badge, and beneath them a strip of daily status
// bars. The whole row links to the detail page. A paused monitor sits at 40%
// opacity. Nullable aggregates arrive absent (undefined) — compare loosely.
export function MonitorListItem({ monitor }: MonitorListItemProps) {
  const status = displayStatus(monitor);
  const response = monitor.avgLatencyMs == null ? "no data" : `${monitor.avgLatencyMs}ms`;
  const uptime =
    monitor.uptimeRatio30d == null ? "—" : `${(monitor.uptimeRatio30d * 100).toFixed(2)}%`;

  return (
    <Link
      href={`/monitors/${monitor.monitorId}`}
      className={cn(
        "flex flex-col gap-2.5 border-b border-border px-5 py-3.5 transition-colors duration-[120ms] last:border-b-0 hover:bg-accent",
        status === "paused" && "opacity-40",
      )}
    >
      <div className="flex items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <StatusDot status={status} />
          <div className="flex min-w-0 flex-col gap-0.5">
            <span className="truncate text-[14px] font-medium">{monitor.name}</span>
            <span className="truncate text-[12px] text-muted-foreground">
              {hostFromUrl(monitor.url)}
            </span>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-4">
          <span className="hidden font-mono text-[12px] tabular-nums text-muted-foreground sm:inline">
            {response}
          </span>
          <span className="w-[64px] text-right font-mono text-[12px] font-semibold tabular-nums">
            {uptime}
          </span>
          <MonitorStatusBadge status={status} />
        </div>
      </div>
      <StatusBarStrip days={monitor.dailyStatus} heights={{ up: 14, alert: 22, none: 6 }} />
    </Link>
  );
}
