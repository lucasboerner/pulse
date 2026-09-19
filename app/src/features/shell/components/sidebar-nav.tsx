"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { LayoutDashboardIcon, Rows3Icon } from "lucide-react";

import { cn } from "@/lib/utils";

const NAV_ITEMS = [
  { href: "/", label: "Overview", icon: LayoutDashboardIcon },
  { href: "/monitors", label: "Monitors", icon: Rows3Icon },
] as const;

export function SidebarNav() {
  const pathname = usePathname();

  return (
    <div className="flex flex-col gap-0.5 px-2">
      {NAV_ITEMS.map((item) => {
        const active =
          item.href === "/" ? pathname === "/" : pathname.startsWith(item.href);
        return (
          <Link
            key={item.href}
            href={item.href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "flex h-9 items-center gap-2.5 px-3 text-[13px] transition-colors duration-[120ms]",
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
