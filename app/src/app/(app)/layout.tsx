import type { ReactNode } from "react";
import { redirect } from "next/navigation";

import { getToken } from "@/lib/auth";
import { Sidebar } from "@/features/shell/components/sidebar";
import { MobileNav } from "@/features/shell/components/mobile-nav";
import { MonitorLiveProvider } from "@/features/monitor/live/monitor-live-context";
import { MercureListener } from "@/features/shell/components/mercure-listener";

// The shell is a Server Component: an inset sidebar on the shell background beside
// one scrolling main column that floats as a rounded card. Below md the rail has no
// room, so the shell stacks — a top bar whose hamburger opens the same navigation in
// a sheet, with the card beneath it.
// The token is checked before any read, so an unauthenticated visitor is redirected
// to /login without a single API call being made. The live provider wraps the whole
// app so a monitor status pushed over Mercure flips in place on every page.
export default async function AppLayout({ children }: { children: ReactNode }) {
  const token = await getToken();
  if (!token) redirect("/login");

  return (
    <MonitorLiveProvider>
      <div className="flex h-svh flex-col overflow-hidden bg-sidebar md:flex-row">
        <MobileNav />
        <Sidebar />
        <main className="m-2 mt-0 flex min-w-0 flex-1 flex-col overflow-y-auto rounded-lg border border-border bg-background md:mt-2">
          {children}
        </main>
        <MercureListener />
      </div>
    </MonitorLiveProvider>
  );
}
