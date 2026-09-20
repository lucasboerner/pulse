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
// never animates. It stays one line at every width — the title truncates and the
// action collapses to its icon rather than the row wrapping.
export function PageHeader({ title, subtitle, leading, updatedLabel, action }: PageHeaderProps) {
  return (
    <header className="flex items-center justify-between gap-3 border-b border-border px-4 py-3.5 sm:gap-4 sm:px-6 sm:py-4.5">
      <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
        {leading}
        <div className="flex min-w-0 flex-col gap-1">
          <h1 className="truncate font-heading text-[18px] leading-[1.15] font-semibold tracking-[-0.01em] sm:text-[22px] sm:leading-[1.1]">
            {title}
          </h1>
          {subtitle ? (
            <p className="truncate text-[12px] text-muted-foreground sm:text-[13px]">{subtitle}</p>
          ) : null}
        </div>
      </div>
      <div className="flex shrink-0 items-center gap-2 sm:gap-3">
        <span className="inline-flex shrink-0 items-center gap-2 whitespace-nowrap text-[12px] tabular-nums text-muted-foreground">
          <span aria-hidden className="size-1.5 rounded-full bg-primary" />
          {updatedLabel ?? "Live"}
        </span>
        {action}
      </div>
    </header>
  );
}
