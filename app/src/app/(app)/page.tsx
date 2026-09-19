import { fetchAll } from "@/lib/api/client";
import { getCurrentUsername } from "@/lib/auth";
import type { InstanceUser, Monitor } from "@/features/monitor/types";
import { relativeTime } from "@/features/monitor/lib/status";
import { PageHeader } from "@/features/shell/components/page-header";
import { OverviewLive } from "@/features/monitor/components/overview-live";
import { MonitorEmptyState } from "@/features/monitor/components/monitor-empty-state";
import { NewMonitorButton } from "@/features/monitor/components/new-monitor-button";

export default async function OverviewPage() {
  const [{ data: monitors }, { data: users }, username] = await Promise.all([
    fetchAll<Monitor>("/api/monitors"),
    fetchAll<InstanceUser>("/api/users"),
    getCurrentUsername(),
  ]);
  const currentUserId = users.find((user) => user.username === username)?.id ?? null;

  const newestCheck = monitors
    .map((monitor) => monitor.lastCheckedAt)
    .filter((value): value is string => Boolean(value))
    .sort()
    .at(-1);
  const updatedLabel = newestCheck ? `Updated ${relativeTime(newestCheck)}` : "Live";

  return (
    <>
      <PageHeader
        title="Overview"
        subtitle="Every monitor, at a glance."
        updatedLabel={updatedLabel}
        action={<NewMonitorButton users={users} currentUserId={currentUserId} />}
      />
      <div className="flex flex-col gap-5 p-6">
        {monitors.length === 0 ? (
          <MonitorEmptyState users={users} currentUserId={currentUserId} />
        ) : (
          <OverviewLive monitors={monitors} />
        )}
      </div>
    </>
  );
}
