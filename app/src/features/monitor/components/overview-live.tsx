"use client";

import type { MetricsSummary } from "@/features/monitor/types";
import { useLiveRollups } from "@/features/monitor/live/monitor-live-context";
import { StatRow } from "@/features/monitor/components/stat-row";
import { MonitorList } from "@/features/monitor/components/monitor-list";
import { OverviewResponseCard } from "@/features/monitor/components/overview-response-card";
import { OverviewIncidentsCard } from "@/features/monitor/components/overview-incidents-card";

interface OverviewLiveProps {
  metrics: MetricsSummary;
}

// The overview's live surface: the "All Systems" rows read the server-delivered
// rollups merged with any live status the Mercure listener has pushed, so a check
// result flips a dot and its badge in place. The aggregate stat/response/incident
// figures stay authoritative from the server render.
export function OverviewLive({ metrics }: OverviewLiveProps) {
  const monitors = useLiveRollups(metrics.monitors);

  return (
    <>
      <StatRow metrics={metrics} />
      <div className="grid grid-cols-1 gap-5 min-[1100px]:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
        <MonitorList monitors={monitors} />
        <div className="flex min-w-0 flex-col gap-5">
          <OverviewResponseCard avgResponseMs={metrics.avgResponseMs} series={metrics.responseSeries} />
          <OverviewIncidentsCard incidents={metrics.recentIncidents} />
        </div>
      </div>
    </>
  );
}
