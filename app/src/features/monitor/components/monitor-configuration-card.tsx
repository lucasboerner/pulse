import type { ReactNode } from "react";

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { InstanceUser, Monitor } from "@/features/monitor/types";
import { intervalLabel, relativeTime } from "@/features/monitor/lib/status";
import { MonitorSubscribersRow } from "@/features/monitor/components/monitor-subscribers-row";

interface ConfigRowProps {
  label: string;
  children: ReactNode;
}

// One label/value row: the uppercase micro-label on the left, the value
// right-aligned, a hairline beneath every row but the last.
function ConfigRow({ label, children }: ConfigRowProps) {
  return (
    <div className="flex flex-col gap-1 border-b border-border py-2.75 last:border-b-0 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
      <span className="shrink-0 text-[11px] tracking-[0.08em] uppercase text-muted-foreground sm:pt-0.5">
        {label}
      </span>
      <div className="min-w-0 text-[13px] sm:text-right">{children}</div>
    </div>
  );
}

interface MonitorConfigurationCardProps {
  monitor: Monitor;
  users: InstanceUser[];
  currentUserId: string | null;
}

// The monitor's settings as read-only label/value rows. Editing them stays in the
// Edit modal; the one interactive control here is the operator's own alert toggle.
export function MonitorConfigurationCard({
  monitor,
  users,
  currentUserId,
}: MonitorConfigurationCardProps) {
  // Nullable API attributes arrive absent (undefined) rather than null when unset,
  // so compare loosely to catch both.
  const expected =
    monitor.expectedStatusCode == null
      ? "status 200–299"
      : `status ${monitor.expectedStatusCode}`;
  const nextCheck = monitor.enabled ? relativeTime(monitor.nextCheckAt) : "paused";

  return (
    <Card>
      <CardHeader>
        <CardTitle>Configuration</CardTitle>
        <CardDescription>Applies from the next check.</CardDescription>
      </CardHeader>
      <CardContent>
        <ConfigRow label="Check Type">HTTP(S) GET</ConfigRow>
        <ConfigRow label="Target">
          <span className="block truncate" title={monitor.url}>
            {monitor.url}
          </span>
        </ConfigRow>
        <ConfigRow label="Interval">every {intervalLabel(monitor.intervalSeconds)}</ConfigRow>
        <ConfigRow label="Timeout">{monitor.timeoutMs}ms</ConfigRow>
        <ConfigRow label="Expected">{expected}</ConfigRow>
        <ConfigRow label="Next Check">{nextCheck}</ConfigRow>
        <ConfigRow label="Alerts">
          <MonitorSubscribersRow
            monitorId={monitor.id}
            subscribers={monitor.subscribers}
            users={users}
            currentUserId={currentUserId}
          />
        </ConfigRow>
      </CardContent>
    </Card>
  );
}
