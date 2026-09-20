import { Wordmark } from "@/features/shell/components/wordmark";
import { SidebarNav } from "@/features/shell/components/sidebar-nav";
import { ThemeSwitch } from "@/features/shell/components/theme-switch";
import { LogoutButton } from "@/features/shell/components/logout-button";
import { intervalLabel } from "@/features/monitor/lib/status";
import type { Monitor } from "@/features/monitor/types";

interface SidebarProps {
  monitors: Monitor[];
}

// The inset 212px sidebar: it sits directly on the shell background (no panel of
// its own — the main column is the floating card) with wordmark over the eyebrow,
// navigation, and the check-interval summary + theme switch + sign-out pinned to
// its bottom edge.
export function Sidebar({ monitors }: SidebarProps) {
  const intervals = monitors.map((monitor) => monitor.intervalSeconds).filter(Boolean);
  const summary = intervals.length
    ? `Checking every ${intervalLabel(Math.min(...intervals))}`
    : "No monitors yet";

  return (
    <nav className="flex w-53 shrink-0 flex-col py-5">
      <div className="px-5 pb-5.5">
        <Wordmark />
      </div>

      <SidebarNav />

      <div className="mt-auto flex flex-col gap-2 border-t border-border px-5 pt-4">
        <span className="text-[11px] tracking-[0.08em] uppercase text-muted-foreground">
          Check Interval
        </span>
        <span className="text-[13px] tabular-nums">{summary}</span>
        <div className="pt-2">
          <ThemeSwitch />
        </div>
        <div className="-mx-1 pt-1">
          <LogoutButton />
        </div>
      </div>
    </nav>
  );
}
