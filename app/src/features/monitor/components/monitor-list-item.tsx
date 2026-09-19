import Link from "next/link";

import { cn } from "@/lib/utils";
import type { Monitor } from "@/features/monitor/types";
import {
  displayStatus,
  hostFromUrl,
  intervalLabel,
  relativeTime,
} from "@/features/monitor/lib/status";
import { StatusDot } from "@/features/monitor/components/status-dot";
import { MonitorStatusBadge } from "@/features/monitor/components/monitor-status-badge";

interface MonitorListItemProps {
  monitor: Monitor;
}

// One overview row: status dot, name over host, interval, last check, badge. The
// whole row links to the detail page (built in phase 6). A paused monitor sits
// at 40% opacity.
export function MonitorListItem({ monitor }: MonitorListItemProps) {
  const status = displayStatus(monitor);

  return (
    <Link
      href={`/monitors/${monitor.id}`}
      className={cn(
        "flex items-center justify-between gap-3 border-b border-border px-5 py-3.5 transition-colors duration-[120ms] last:border-b-0 hover:bg-accent",
        status === "paused" && "opacity-40",
      )}
    >
      <div className="flex min-w-0 items-center gap-3">
        <StatusDot status={status} />
        <div className="flex min-w-0 flex-col gap-0.5">
          <span className="truncate text-[14px] font-medium">{monitor.name}</span>
          <span className="truncate text-[12px] text-muted-foreground">
            {hostFromUrl(monitor.url)}
          </span>
        </div>
      </div>
      <div className="flex shrink-0 items-center gap-4 text-[12px] tabular-nums text-muted-foreground">
        <span className="hidden sm:inline">every {intervalLabel(monitor.intervalSeconds)}</span>
        <span className="hidden md:inline">{relativeTime(monitor.lastCheckedAt)}</span>
        <MonitorStatusBadge status={status} />
      </div>
    </Link>
  );
}
