"use client";

import { useEffect, useSyncExternalStore } from "react";
import { useTheme } from "next-themes";

import { Switch } from "@/components/ui/switch";

// Detects hydration without a setState-in-effect: false on the server, true once
// mounted on the client. Until then the switch cannot know the theme — next-themes
// resolves it only in the browser — so a stand-in stands in for it.
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

  const isDark = resolvedTheme === "dark";

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
        <span className="dark:hidden">Light</span>
        <span className="hidden dark:inline">Dark</span>
      </span>
      {mounted ? (
        <Switch
          checked={isDark}
          onCheckedChange={(value) => setTheme(value ? "dark" : "light")}
          aria-label="Toggle dark mode"
          aria-keyshortcuts="d"
        />
      ) : (
        // The pre-hydration stand-in: same 40×22 geometry, but its state comes from
        // the `.dark` class next-themes writes before first paint, so it already
        // sits on the right side and never animates across on hydration.
        <span
          aria-hidden
          className="relative inline-flex h-[22px] w-[40px] shrink-0 items-center rounded-full border border-transparent bg-input px-0.5 dark:bg-primary"
        >
          <span className="block size-[18px] rounded-full bg-primary-foreground dark:translate-x-[18px]" />
        </span>
      )}
    </div>
  );
}
