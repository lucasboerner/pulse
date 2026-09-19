import { cn } from "@/lib/utils";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { DailyStatus } from "@/features/monitor/types";
import { StatusBarStrip } from "@/features/monitor/components/status-bar-strip";

interface StatusHistoryCardProps {
  days: DailyStatus[];
}

const LEGEND = [
  { label: "Up", className: "bg-success" },
  { label: "Degraded", className: "bg-warning" },
  { label: "Down", className: "bg-destructive" },
] as const;

// The 90-day status history: a taller version of the overview's daily bar strip,
// with a small legend so the three status colours read on their own.
export function StatusHistoryCard({ days }: StatusHistoryCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Status History</CardTitle>
        <CardDescription>Daily status, last 90 days</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <StatusBarStrip days={days} heights={{ up: 20, alert: 34, none: 8 }} />
        <div className="flex flex-wrap items-center gap-4">
          {LEGEND.map((item) => (
            <span
              key={item.label}
              className="inline-flex items-center gap-1.5 text-[11px] text-muted-foreground"
            >
              <span aria-hidden className={cn("size-2 rounded-[2px]", item.className)} />
              {item.label}
            </span>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
