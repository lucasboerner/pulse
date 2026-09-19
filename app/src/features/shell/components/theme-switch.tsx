"use client";

import { useSyncExternalStore } from "react";
import { useTheme } from "next-themes";

import { Switch } from "@/components/ui/switch";

// Detects hydration without a setState-in-effect: false on the server, true once
// mounted on the client. This keeps the switch from rendering a theme it cannot
// know server-side (next-themes resolves the theme only in the browser).
const noopSubscribe = () => () => {};
function useMounted(): boolean {
  return useSyncExternalStore(
    noopSubscribe,
    () => true,
    () => false,
  );
}

export function ThemeSwitch() {
  const { resolvedTheme, setTheme } = useTheme();
  const mounted = useMounted();

  // Dark is canonical, so the pre-mount guess is dark; after mount the real
  // resolved theme takes over (suppressHydrationWarning on <html> covers the gap).
  const isDark = mounted ? resolvedTheme === "dark" : true;

  return (
    <div className="flex items-center justify-between gap-2">
      <span className="text-[11px] tracking-[0.08em] uppercase text-muted-foreground">
        {mounted ? (isDark ? "Dark" : "Light") : "Theme"}
      </span>
      <Switch
        checked={isDark}
        onCheckedChange={(value) => setTheme(value ? "dark" : "light")}
        aria-label="Toggle dark mode"
      />
    </div>
  );
}
