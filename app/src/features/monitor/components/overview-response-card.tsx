import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { HistoryBucket } from "@/features/monitor/types";
import { ResponseTimeChart } from "@/features/monitor/components/response-time-chart";

interface OverviewResponseCardProps {
  avgResponseMs: number | null;
  series: HistoryBucket[];
}

// The overview's response card: the fleet's average response as the headline
// figure, then the shared area+line sparkline of the cross-monitor 24h median
// series. Nullable aggregate arrives absent (undefined) — compare loosely.
export function OverviewResponseCard({ avgResponseMs, series }: OverviewResponseCardProps) {
  const headline = avgResponseMs == null ? "—" : `${avgResponseMs}ms`;

  return (
    <Card>
      <CardHeader>
        <CardTitle>Response Time</CardTitle>
        <CardDescription>Median across regions, last 24h</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <span className="font-mono text-[28px] leading-none font-bold tabular-nums">
          {headline}
        </span>
        <ResponseTimeChart series={series} />
      </CardContent>
    </Card>
  );
}
