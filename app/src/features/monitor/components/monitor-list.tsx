import type { Monitor } from "@/features/monitor/types";
import { MonitorListItem } from "@/features/monitor/components/monitor-list-item";

interface MonitorListProps {
  monitors: Monitor[];
}

export function MonitorList({ monitors }: MonitorListProps) {
  return (
    <section className="border border-border bg-card">
      <div className="flex items-center justify-between gap-3 border-b border-border px-5 py-3.5">
        <span className="text-[15px] font-semibold">All Systems</span>
        <span className="text-[12px] tabular-nums text-muted-foreground">
          {monitors.length} {monitors.length === 1 ? "monitor" : "monitors"}
        </span>
      </div>
      <ul>
        {monitors.map((monitor) => (
          <li key={monitor.id}>
            <MonitorListItem monitor={monitor} />
          </li>
        ))}
      </ul>
    </section>
  );
}
