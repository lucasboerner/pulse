"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { LayoutDashboardIcon, Rows3Icon } from "lucide-react";

import { cn } from "@/lib/utils";

const NAV_ITEMS = [
  { href: "/", label: "Overview", icon: LayoutDashboardIcon },
  { href: "/monitors", label: "Monitors", icon: Rows3Icon },
] as const;

// The whole navigation is two entries, so it needs no drawer: below md it lays the
// pair side by side as a hairline segmented control in the top bar, and from md up
// it is the sidebar's stacked list again.
export function SidebarNav() {
  const pathname = usePathname();

  return (
    <div className="flex gap-1 rounded-md border border-border p-1 md:flex-col md:gap-0.5 md:rounded-none md:border-0 md:p-0 md:px-2">
      {NAV_ITEMS.map((item) => {
        const active =
          item.href === "/" ? pathname === "/" : pathname.startsWith(item.href);
        return (
          <Link
            key={item.href}
            href={item.href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "flex h-9 flex-1 items-center justify-center gap-2.5 rounded-sm px-3 text-[13px] transition-colors duration-[120ms] md:h-9 md:flex-none md:justify-start",
              active
                ? "bg-accent text-foreground"
                : "text-muted-foreground hover:bg-accent hover:text-foreground",
            )}
          >
            <item.icon className="size-[15px]" strokeWidth={2} />
            {item.label}
          </Link>
        );
      })}
    </div>
  );
}
