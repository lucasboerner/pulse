import { StatBlock } from "@/features/monitor/components/stat-block";
import type { MetricsSummary } from "@/features/monitor/types";

interface StatRowProps {
  metrics: MetricsSummary;
}

// Packed edge to edge: a 1px grid gap over the border colour, so the hairline is
// the gutter and the block reads as one ruled instrument panel, clipped to the
// rounded corner. The four fleet aggregates come straight from the metrics read.
export function StatRow({ metrics }: StatRowProps) {
  // Nullable aggregates arrive absent (undefined) rather than null when no check
  // falls in the window, so compare loosely to catch both.
  const uptime =
    metrics.uptimeRatio30d == null ? "—" : `${(metrics.uptimeRatio30d * 100).toFixed(2)}%`;
  const avgResponse = metrics.avgResponseMs == null ? "—" : `${metrics.avgResponseMs}ms`;

  return (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(136px,1fr))] gap-px overflow-hidden rounded-lg border border-border bg-border">
      <StatBlock
        label="Systems Up"
        value={`${metrics.monitorsUp}/${metrics.monitorsTotal}`}
        footLeft="online"
        footRight={`${metrics.monitorsPaused} paused`}
      />
      <StatBlock label="Uptime 30d" value={uptime} footLeft="30-day" footRight="SLO 99.90%" />
      <StatBlock label="Avg Response" value={avgResponse} footLeft="mean" footRight="last 24h" />
      <StatBlock
        label="Open Incidents"
        value={String(metrics.openIncidents)}
        tone={metrics.openIncidents > 0 ? "destructive" : "success"}
        footLeft="unresolved"
        footRight={`${metrics.needingAttention} affected`}
      />
    </div>
  );
}
