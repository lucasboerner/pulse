import type { ReactNode } from "react";
import { redirect } from "next/navigation";

import { getToken } from "@/lib/auth";
import { fetchAll } from "@/lib/api/client";
import type { Monitor } from "@/features/monitor/types";
import { Sidebar } from "@/features/shell/components/sidebar";
import { MercureListener } from "@/features/shell/components/mercure-listener";

// The shell is a Server Component: fixed sidebar beside one scrolling main
// column. The token is checked before any read, so an unauthenticated visitor is
// redirected to /login without a single API call being made.
export default async function AppLayout({ children }: { children: ReactNode }) {
  const token = await getToken();
  if (!token) redirect("/login");

  // The sidebar's check-interval summary needs the monitor collection.
  const { data: monitors } = await fetchAll<Monitor>("/api/monitors");
  const mercureUrl = process.env.MERCURE_PUBLIC_URL;

  return (
    <div className="flex h-svh overflow-hidden">
      <Sidebar monitors={monitors} />
      <main className="flex min-w-0 flex-1 flex-col overflow-y-auto">{children}</main>
      {mercureUrl ? <MercureListener url={mercureUrl} topic="pulse://monitors" /> : null}
    </div>
  );
}
