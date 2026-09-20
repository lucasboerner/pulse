import { fetchAll, fetchResource } from "@/lib/api/client";
import { getCurrentUsername } from "@/lib/auth";
import type { InstanceUser, MetricsSummary } from "@/features/monitor/types";
import { PageHeader } from "@/features/shell/components/page-header";
import { OverviewLive } from "@/features/monitor/components/overview-live";
import { MonitorEmptyState } from "@/features/monitor/components/monitor-empty-state";
import { NewMonitorButton } from "@/features/monitor/components/new-monitor-button";

// The overview reads one fleet-wide metrics aggregate for the stats, the response
// series, the per-monitor rollups and the recent incidents; the users list plus the
// current username still drive the New Monitor button's alert-recipient defaults.
export default async function OverviewPage() {
  const [metrics, { data: users }, username] = await Promise.all([
    fetchResource<MetricsSummary>("/api/metrics"),
    fetchAll<InstanceUser>("/api/users"),
    getCurrentUsername(),
  ]);
  const currentUserId = users.find((user) => user.username === username)?.id ?? null;

  return (
    <>
      <PageHeader
        title="Overview"
        subtitle="Every monitor, at a glance."
        action={<NewMonitorButton users={users} currentUserId={currentUserId} compact />}
      />
      <div className="flex flex-col gap-4 p-4 sm:gap-5 sm:p-6">
        {metrics.monitorsTotal === 0 ? (
          <MonitorEmptyState users={users} currentUserId={currentUserId} />
        ) : (
          <OverviewLive metrics={metrics} />
        )}
      </div>
    </>
  );
}
