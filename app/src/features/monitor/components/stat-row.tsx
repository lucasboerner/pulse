import { cn } from "@/lib/utils";
import type { Monitor } from "@/features/monitor/types";

interface StatBlockProps {
  label: string;
  value: number;
  emphasis?: boolean;
  dotClass?: string;
}

// The heaviest number on the screen is a positive one (Systems Up). A failure
// count is the same weight as the others and never grows into an alarm — its
// only colour is the small dot beside its label.
function StatBlock({ label, value, emphasis, dotClass }: StatBlockProps) {
  return (
    <div className="flex flex-col gap-3 bg-card p-5">
      <span className="inline-flex items-center gap-2 text-[11px] tracking-[0.08em] uppercase text-muted-foreground">
        {dotClass ? <span aria-hidden className={cn("size-1.5 rounded-full", dotClass)} /> : null}
        {label}
      </span>
      <span
        className={cn(
          "leading-none tabular-nums",
          emphasis ? "text-[28px] font-bold" : "text-[22px] font-semibold",
        )}
      >
        {value}
      </span>
    </div>
  );
}

interface StatRowProps {
  monitors: Monitor[];
}

// Packed edge to edge: a 1px grid gap over the border colour, so the hairline is
// the gutter and the block reads as one ruled instrument panel.
export function StatRow({ monitors }: StatRowProps) {
  const total = monitors.length;
  const paused = monitors.filter((monitor) => !monitor.enabled).length;
  const active = monitors.filter((monitor) => monitor.enabled);
  const up = active.filter((monitor) => monitor.lastStatus === "up").length;
  const down = active.filter((monitor) => monitor.lastStatus === "down").length;

  return (
    <div className="grid grid-cols-[repeat(auto-fit,minmax(136px,1fr))] gap-px border border-border bg-border">
      <StatBlock label="Monitors" value={total} />
      <StatBlock label="Up" value={up} emphasis dotClass="bg-success" />
      <StatBlock label="Down" value={down} dotClass="bg-destructive" />
      <StatBlock label="Paused" value={paused} dotClass="bg-muted-foreground" />
    </div>
  );
}
