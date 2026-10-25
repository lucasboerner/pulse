import type { Metadata } from "next";
import { JetBrains_Mono, Space_Grotesk } from "next/font/google";
import { connection } from "next/server";
import "@/app/globals.css";
import { cn } from "@/lib/utils";
import { SENTRY_META, sentryConfigFromEnv } from "@/lib/sentry";
import { Providers } from "@/components/providers";
import React from "react";

// The type system is split: Space Grotesk is the body/UI sans, JetBrains Mono
// carries headings and numbers (tabular). Both variable fonts are exposed as CSS
// variables so globals.css can map them onto Tailwind's font tokens — --font-sans
// (body default) → Space Grotesk, --font-heading / --font-mono → JetBrains Mono.
const sans = Space_Grotesk({
  subsets: ["latin"],
  variable: "--font-app-sans",
  display: "swap",
});

const mono = JetBrains_Mono({
  subsets: ["latin"],
  variable: "--font-app-mono",
  display: "swap",
});

export const metadata: Metadata = {
  title: "Pulse — Uptime Monitoring",
  description: "Self-hosted uptime monitoring for domains and the services attached to them.",
};

export default async function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  // The Sentry config is the container's, not the build machine's: wait for a request
  // before reading it, so no page is prerendered with whatever the build saw.
  await connection();
  const sentry = sentryConfigFromEnv();

  return (
    // suppressHydrationWarning is required by next-themes: it writes the theme
    // class onto <html> before React hydrates, so server and client markup differ
    // by design on this one element.
    <html
      lang="en"
      suppressHydrationWarning
      className={cn("h-full", "antialiased", "font-sans", sans.variable, mono.variable)}
    >
      <body className="min-h-full flex flex-col">
        {/* Opt-in error reporting: instrumentation-client.ts initialises Sentry in the
            browser only when it finds these. React hoists them into <head>. */}
        {sentry && (
          <>
            <meta name={SENTRY_META.dsn} content={sentry.dsn} />
            <meta name={SENTRY_META.environment} content={sentry.environment} />
            <meta name={SENTRY_META.release} content={sentry.release} />
          </>
        )}
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
