"use client";

import type { Monitor } from "@/features/monitor/types";
import { useLiveMonitors } from "@/features/monitor/live/monitor-live-context";
import { StatRow } from "@/features/monitor/components/stat-row";
import { MonitorList } from "@/features/monitor/components/monitor-list";

// The overview's live surface: the stat counts and the monitor list both read the
// server-delivered monitors merged with any live status the Mercure listener has
// pushed, so a check result flips a dot and its count together, in place.
export function OverviewLive({ monitors }: { monitors: Monitor[] }) {
  const live = useLiveMonitors(monitors);

  return (
    <>
      <StatRow monitors={live} />
      <MonitorList monitors={live} />
    </>
  );
}
