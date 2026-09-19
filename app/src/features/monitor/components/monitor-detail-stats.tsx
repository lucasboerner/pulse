import { StatBlock } from "@/features/monitor/components/stat-block";
import type { MonitorHistory } from "@/features/monitor/types";

interface MonitorDetailStatsProps {
  history: MonitorHistory;
}

// Nullable API ratios arrive absent (undefined) rather than null when the window
// holds no check, so compare loosely to catch both.
function uptimePercent(ratio: number | null | undefined): string {
  return ratio == null ? "—" : `${(ratio * 100).toFixed(2)}%`;
}

// The detail page's four-cell stat row, packed edge to edge with a 1px grid gap
// over the border colour and clipped to the rounded corner. The three uptime
// windows lead; the median response closes the row. Shares the StatBlock face with
// the overview KPIs, with the window as the footnote.
export function MonitorDetailStats({ history }: MonitorDetailStatsProps) {
  const response = history.medianLatencyMs == null ? "—" : `${history.medianLatencyMs}ms`;

  return (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(136px,1fr))] gap-px overflow-hidden rounded-lg border border-border bg-border">
      <StatBlock label="Uptime 24h" value={uptimePercent(history.uptimeRatio)} footRight="24 hours" />
      <StatBlock label="Uptime 7d" value={uptimePercent(history.uptimeRatio7d)} footRight="7 days" />
      <StatBlock label="Uptime 30d" value={uptimePercent(history.uptimeRatio30d)} footRight="30 days" />
      <StatBlock label="Response" value={response} footRight="median" />
    </div>
  );
}
