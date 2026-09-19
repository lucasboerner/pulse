import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress, type ProgressTone } from "@/components/ui/progress";

interface SloBudgetCardProps {
  uptimeRatio30d: number | null;
}

const TARGET_UPTIME_PCT = 99.9;

// The 30-day error budget, derived on the frontend from the 30-day uptime against a
// 99.90% target. `consumed` is the share of the 0.1% budget spent, scaled to 0–100;
// the tone alarms as the budget runs low. Nullable ratio arrives absent (undefined)
// when the window holds no check — compare loosely and show an empty budget.
export function SloBudgetCard({ uptimeRatio30d }: SloBudgetCardProps) {
  const uptimePct = uptimeRatio30d == null ? null : uptimeRatio30d * 100;
  const consumed = uptimePct == null ? 0 : Math.min(100, Math.max(0, (100 - uptimePct) / 0.1));
  const tone: ProgressTone =
    uptimePct == null || uptimePct >= 99.9 ? "brand" : uptimePct >= 99.5 ? "warning" : "destructive";
  const label = `${Math.round(consumed)}% used`;

  return (
    <Card>
      <CardHeader>
        <CardTitle>SLO Budget</CardTitle>
        <CardDescription>30-day error budget</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <div className="flex items-center justify-between">
          <span className="text-[13px] text-muted-foreground">Error budget</span>
          <span className="font-mono text-[13px] font-semibold tabular-nums">{label}</span>
        </div>
        <Progress value={consumed} tone={tone} />
        <p className="text-[11px] text-muted-foreground">
          Target {TARGET_UPTIME_PCT.toFixed(2)}% uptime over the last 30 days.
        </p>
      </CardContent>
    </Card>
  );
}
