"use client";

import { useEffect, useSyncExternalStore } from "react";
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

  // `d` flips the theme from anywhere in the shell — but never while the caret sits
  // in a field, so typing a "d" into a monitor name cannot repaint the interface.
  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key !== "d" && event.key !== "D") return;
      if (event.metaKey || event.ctrlKey || event.altKey || event.repeat) return;
      const target = event.target as HTMLElement | null;
      if (
        target &&
        (target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName))
      ) {
        return;
      }
      setTheme(resolvedTheme === "dark" ? "light" : "dark");
    }

    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [resolvedTheme, setTheme]);

  return (
    <div className="flex items-center justify-between gap-2">
      <span className="text-[11px] tracking-[0.08em] uppercase text-muted-foreground">
        {mounted ? (isDark ? "Dark" : "Light") : "Theme"}
      </span>
      <Switch
        checked={isDark}
        onCheckedChange={(value) => setTheme(value ? "dark" : "light")}
        aria-label="Toggle dark mode"
        aria-keyshortcuts="d"
      />
    </div>
  );
}
