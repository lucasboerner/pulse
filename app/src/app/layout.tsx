import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "@/app/globals.css";
import { cn } from "@/lib/utils";
import { Providers } from "@/components/providers";
import React from "react";

// The font is exposed as a CSS variable so globals.css can map it onto Tailwind's
// --font-sans token, rather than each component reaching for the font class.
const sans = Inter({ subsets: ["latin"], variable: "--font-app-sans" });

export const metadata: Metadata = {
  title: "Pulse",
  description: "Pulse",
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
      className={cn("h-full", "antialiased", "font-sans", sans.variable)}
    >
      <body className="min-h-full flex flex-col">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
