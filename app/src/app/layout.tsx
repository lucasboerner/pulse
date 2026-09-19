import type { Metadata } from "next";
import { JetBrains_Mono } from "next/font/google";
import "@/app/globals.css";
import { cn } from "@/lib/utils";
import { Providers } from "@/components/providers";
import React from "react";

// JetBrains Mono is the whole identity — there is no separate sans. The variable
// font (300–700) is exposed as a CSS variable so globals.css can map it onto
// Tailwind's --font-sans token, making the entire tree monospaced by default.
const mono = JetBrains_Mono({
  subsets: ["latin"],
  variable: "--font-app-sans",
  display: "swap",
});

export const metadata: Metadata = {
  title: "Pulse — Uptime Monitoring",
  description: "Self-hosted uptime monitoring for domains and the services attached to them.",
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    // suppressHydrationWarning is required by next-themes: it writes the theme
    // class onto <html> before React hydrates, so server and client markup differ
    // by design on this one element.
    <html
      lang="en"
      suppressHydrationWarning
      className={cn("h-full", "antialiased", "font-sans", mono.variable)}
    >
      <body className="min-h-full flex flex-col">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
