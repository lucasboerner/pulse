"use client";

import { ThemeProvider } from "next-themes";
import { TooltipProvider } from "@/components/ui/tooltip";
import { Toaster } from "@/components/ui/sonner";

/**
 * Every global client-side provider, in one boundary. Keep this list short —
 * anything added here becomes a client component wrapping the whole tree.
 *
 * `attribute="class"` is what makes the `.dark` token block in globals.css apply;
 * `disableTransitionOnChange` avoids every colour transitioning at once on toggle.
 */
export function Providers({ children }: { children: React.ReactNode }) {
  return (
    <ThemeProvider attribute="class" defaultTheme="system" enableSystem disableTransitionOnChange>
      <TooltipProvider>
        {children}
        <Toaster />
      </TooltipProvider>
    </ThemeProvider>
  );
}
