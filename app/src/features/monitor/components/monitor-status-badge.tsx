import { Badge } from "@/components/ui/badge";
import { statusMeta, type DisplayStatus } from "@/features/monitor/lib/status";

interface MonitorStatusBadgeProps {
  status: DisplayStatus;
}

export function MonitorStatusBadge({ status }: MonitorStatusBadgeProps) {
  const meta = statusMeta(status);
  return (
    <Badge variant={meta.badge} dot>
      {meta.label}
    </Badge>
  );
}
