import { cn } from "@/lib/utils";
import type { RecentCheck } from "@/features/monitor/types";
import { relativeTime } from "@/features/monitor/lib/status";
import { MonitorStatusBadge } from "@/features/monitor/components/monitor-status-badge";

interface RecentChecksTableProps {
  checks: RecentCheck[];
  now: number;
}

const GRID = "grid-cols-[minmax(96px,1fr)_100px_minmax(90px,1.4fr)_90px]";

// The latest probe results as a dense table: an uppercase header row on the muted
// surface, a hairline under each row, numbers right-aligned and tabular. The HTTP
// code carries the status colour; a check with no response shows its error message
// in place of the code and a dash for the latency.
export function RecentChecksTable({ checks, now }: RecentChecksTableProps) {
  if (checks.length === 0) {
    return <p className="py-6 text-[13px] text-muted-foreground">No checks recorded yet.</p>;
  }

  return (
    <div className="overflow-x-auto">
      <div className="min-w-[440px]">
        <div
          className={cn(
            "grid h-[38px] items-center gap-3 bg-muted px-5 text-[11px] tracking-[0.08em] uppercase text-muted-foreground",
            GRID,
          )}
        >
          <span>Checked</span>
          <span>Status</span>
          <span>Code</span>
          <span className="text-right">Latency</span>
        </div>

        {checks.map((check, index) => {
          const hasResponse = check.httpStatusCode != null;
          const codeClass =
            check.httpStatusCode != null &&
            check.httpStatusCode >= 200 &&
            check.httpStatusCode < 300
              ? "text-success"
              : "text-destructive";

          return (
            <div
              key={`${check.checkedAt}-${index}`}
              className={cn(
                "grid items-center gap-3 border-b border-border px-5 py-2.75 last:border-b-0",
                GRID,
              )}
            >
              <span className="text-[13px] tabular-nums text-muted-foreground">
                {relativeTime(check.checkedAt, now)}
              </span>

              <span>
                <MonitorStatusBadge status={check.status} />
              </span>

              {hasResponse ? (
                <span className={cn("text-[13px] tabular-nums", codeClass)}>
                  {check.httpStatusCode}
                </span>
              ) : (
                <span
                  className="block truncate text-[13px] text-muted-foreground"
                  title={check.errorMessage ?? undefined}
                >
                  {check.errorMessage ?? "No response"}
                </span>
              )}

              <span className="text-right text-[13px] tabular-nums text-muted-foreground">
                {check.latencyMs == null ? "—" : `${check.latencyMs} ms`}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}
