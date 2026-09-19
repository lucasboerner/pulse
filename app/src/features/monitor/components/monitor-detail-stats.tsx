import { cn } from "@/lib/utils";
import type { MonitorHistory } from "@/features/monitor/types";

interface StatCellProps {
  label: string;
  value: string;
  footnote: string;
  emphasis?: boolean;
}

// One cell of the packed panel: an uppercase micro-label, the figure in tabular
// numerals, and a muted footnote right-aligned beneath it. The emphasised cell
// (uptime) is the heaviest element on the page — a positive number, never a
// failure count.
function StatCell({ label, value, footnote, emphasis }: StatCellProps) {
  return (
    <div className="flex flex-col gap-3 bg-card p-5">
      <span className="text-[11px] tracking-[0.08em] uppercase text-muted-foreground">{label}</span>
      <span
        className={cn(
          "leading-none tabular-nums",
          emphasis ? "text-[28px] font-bold" : "text-[22px] font-semibold",
        )}
      >
        {value}
      </span>
      <span className="text-right text-[11px] text-muted-foreground">{footnote}</span>
    </div>
  );
}

interface MonitorDetailStatsProps {
  history: MonitorHistory;
}

// The detail page's four-cell stat row, packed edge to edge with a 1px grid gap
// over the border colour. Uptime carries the visual weight.
export function MonitorDetailStats({ history }: MonitorDetailStatsProps) {
  // Nullable API attributes arrive absent (undefined) rather than null when the
  // window holds no check, so compare loosely to catch both.
  const uptime =
    history.uptimeRatio == null ? "—" : `${(history.uptimeRatio * 100).toFixed(2)} %`;
  const response = history.medianLatencyMs == null ? "—" : `${history.medianLatencyMs} ms`;

  return (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(136px,1fr))] gap-px border border-border bg-border">
      <StatCell label="Uptime 24h" value={uptime} footnote="last 24 hours" emphasis />
      <StatCell label="Response" value={response} footnote="median" />
      <StatCell label="Checks 24h" value={String(history.checkCount)} footnote="results" />
      <StatCell
        label="Incidents 30d"
        value={String(history.incidentCount30d)}
        footnote="30 days"
      />
    </div>
  );
}
