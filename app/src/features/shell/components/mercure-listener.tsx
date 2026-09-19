"use client";

import { useEffect } from "react";

import { useApplyMonitorUpdate } from "@/features/monitor/live/monitor-live-context";

/**
 * Subscribes to live monitor updates with a native EventSource and patches each
 * pushed check result into the live store, which flips the matching row in place.
 *
 * The stream is a same-origin route (/mercure) that proxies the Mercure hub: the
 * browser speaks only to the Next server, so there is no cross-origin request, no
 * mixed-content block over https and no cross-subdomain cookie — and EventSource,
 * which cannot send an Authorization header, needs none. The proxy attaches the
 * subscribe-only capability token server-side. EventSource reconnects on its own,
 * so a dropped stream or a briefly dead hub just recovers with no code here.
 */
export function MercureListener() {
  const apply = useApplyMonitorUpdate();

  useEffect(() => {
    const source = new EventSource("/mercure");

    source.onmessage = (event) => {
      try {
        const payload = JSON.parse(event.data) as {
          id?: unknown;
          status?: unknown;
          checkedAt?: unknown;
        };
        if (typeof payload.id !== "string") return;
        apply({
          id: payload.id,
          status:
            payload.status === "up" || payload.status === "down" || payload.status === "degraded"
              ? payload.status
              : null,
          checkedAt: typeof payload.checkedAt === "string" ? payload.checkedAt : null,
        });
      } catch {
        // A frame we cannot parse is ignored; the next check result recovers it.
      }
    };

    return () => source.close();
  }, [apply]);

  return null;
}
