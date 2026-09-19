import type { ReactNode } from "react";

interface PageHeaderProps {
  title: string;
  subtitle?: string;
  /** An optional leading control before the title (e.g. a back button). */
  leading?: ReactNode;
  /** The right-hand live-update label (e.g. the newest check time). */
  updatedLabel?: string;
  /** The primary action, rendered on the right. */
  action?: ReactNode;
}

// The per-route header bar: an optional leading control then title and subtitle on
// the left; the static live-update dot with its label and the primary action on the
// right; a hairline bottom border. The dot is teal (interface, never status) and
// never animates.
export function PageHeader({ title, subtitle, leading, updatedLabel, action }: PageHeaderProps) {
  return (
    <header className="flex flex-wrap items-center justify-between gap-4 border-b border-border px-6 py-4.5">
      <div className="flex min-w-0 items-center gap-3">
        {leading}
        <div className="flex min-w-0 flex-col gap-1">
          <h1 className="font-heading text-[22px] leading-[1.1] font-semibold tracking-[-0.01em]">
            {title}
          </h1>
          {subtitle ? <p className="text-[13px] text-muted-foreground">{subtitle}</p> : null}
        </div>
      </div>
      <div className="flex items-center gap-3">
        <span className="inline-flex items-center gap-2 text-[12px] tabular-nums text-muted-foreground">
          <span aria-hidden className="size-1.5 rounded-full bg-primary" />
          {updatedLabel ?? "Live"}
        </span>
        {action}
      </div>
    </header>
  );
}
