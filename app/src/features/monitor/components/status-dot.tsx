import { cn } from "@/lib/utils";
import { statusDotClass, type DisplayStatus } from "@/features/monitor/lib/status";

interface StatusDotProps {
  status: DisplayStatus;
  className?: string;
}

// The one round thing in the interface. Status hue lives here (and in the badge).
export function StatusDot({ status, className }: StatusDotProps) {
  return (
    <span
      aria-hidden
      className={cn("inline-block size-2 shrink-0 rounded-full", statusDotClass(status), className)}
    />
  );
}
