import { cn } from "@/lib/utils";

interface StatBlockProps {
  label: string;
  value: string;
  footLeft?: string;
  footRight?: string;
  tone?: "success" | "destructive";
}

// The design-system StatBlock, shared by the overview KPIs and the detail stat row:
// an uppercase caps label, an oversized mono value on the 3xl step, a thin underline
// rule, and a footnote row (muted left, emphasised right). The whole block is mono
// with tabular figures so a live swap never jitters.
export function StatBlock({ label, value, footLeft, footRight, tone }: StatBlockProps) {
  return (
    <div className="flex flex-col gap-2.5 bg-card p-4 font-mono sm:p-5">
      <span className="text-[11px] font-medium tracking-[0.08em] uppercase text-muted-foreground">
        {label}
      </span>
      <span
        className={cn(
          "text-[22px] font-bold leading-none tabular-nums sm:text-[26px]",
          tone === "destructive"
            ? "text-destructive"
            : tone === "success"
              ? "text-success"
              : "text-foreground",
        )}
      >
        {value}
      </span>
      <div aria-hidden className="h-px bg-border" />
      {footLeft || footRight ? (
        <div className="flex justify-between text-[13px] text-muted-foreground">
          <span>{footLeft}</span>
          <span className="text-foreground tabular-nums">{footRight}</span>
        </div>
      ) : null}
    </div>
  );
}
