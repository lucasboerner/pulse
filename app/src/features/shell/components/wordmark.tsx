import { cn } from "@/lib/utils";

interface WordmarkProps {
  className?: string;
  /** Drops the eyebrow — for the mobile top bar, where the bar is one line tall. */
  compact?: boolean;
}

// PULSE, uppercase and tracked, over an uppercase eyebrow. The one wordmark,
// reused by the sidebar, the mobile top bar and the login panel.
export function Wordmark({ className, compact }: WordmarkProps) {
  return (
    <div className={cn("flex flex-col gap-1.5", className)}>
      <span className="font-heading text-[15px] leading-none font-bold tracking-[0.18em]">
        PULSE
      </span>
      {compact ? null : (
        <span className="text-[11px] leading-none tracking-[0.08em] uppercase text-muted-foreground">
          Uptime Monitoring
        </span>
      )}
    </div>
  );
}
