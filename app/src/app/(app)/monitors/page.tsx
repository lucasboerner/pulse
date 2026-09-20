import { fetchAll } from "@/lib/api/client";
import { getCurrentUsername } from "@/lib/auth";
import type { InstanceUser, Monitor } from "@/features/monitor/types";
import { relativeTime } from "@/features/monitor/lib/status";
import { PageHeader } from "@/features/shell/components/page-header";
import { MonitorsTable } from "@/features/monitor/components/monitors-table";
import { NewMonitorButton } from "@/features/monitor/components/new-monitor-button";

export default async function MonitorsPage() {
  const [{ data: monitors }, { data: users }, username] = await Promise.all([
    fetchAll<Monitor>("/api/monitors"),
    fetchAll<InstanceUser>("/api/users"),
    getCurrentUsername(),
  ]);
  const currentUserId = users.find((user) => user.username === username)?.id ?? null;
  // A Server Component renders once per request, so a single request-time clock is
  // stable; it is threaded into the client table so SSR and hydration match.
  // eslint-disable-next-line react-hooks/purity
  const now = Date.now();

  const newestCheck = monitors
    .map((monitor) => monitor.lastCheckedAt)
    .filter((value): value is string => Boolean(value))
    .sort()
    .at(-1);
  const updatedLabel = newestCheck ? `Updated ${relativeTime(newestCheck, now)}` : "Live";

  return (
    <>
      <PageHeader
        title="Monitors"
        subtitle="Every target and its current state."
        updatedLabel={updatedLabel}
        action={<NewMonitorButton users={users} currentUserId={currentUserId} compact />}
      />
      <div className="p-4 sm:p-6">
        <MonitorsTable
          monitors={monitors}
          users={users}
          currentUserId={currentUserId}
          now={now}
        />
      </div>
    </>
  );
}
