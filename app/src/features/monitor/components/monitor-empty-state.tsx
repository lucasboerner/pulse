import { NewMonitorButton } from "@/features/monitor/components/new-monitor-button";
import type { InstanceUser } from "@/features/monitor/types";

interface MonitorEmptyStateProps {
  users: InstanceUser[];
  currentUserId: string | null;
}

// 48px of air, one muted sentence, one outline button that opens the New Monitor
// modal. No illustration, no headline.
export function MonitorEmptyState({ users, currentUserId }: MonitorEmptyStateProps) {
  return (
    <div className="flex flex-col items-center justify-center gap-4 rounded-lg border border-border bg-card px-6 py-12 text-center">
      <p className="text-[13px] text-muted-foreground">
        No monitors yet — add the first target to start checking.
      </p>
      <NewMonitorButton users={users} currentUserId={currentUserId} variant="outline" />
    </div>
  );
}
