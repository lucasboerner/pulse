"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

interface MercureListenerProps {
  url: string;
  topic: string;
}

/**
 * Subscribes to the Mercure hub and re-reads the page when a message arrives.
 * The message is a signal, never the data — router.refresh() re-runs the Server
 * Component read. A burst of check results is debounced into one refresh, and a
 * dead hub simply stops updates: no overlay, no toast, no retry storm.
 */
export function MercureListener({ url, topic }: MercureListenerProps) {
  const router = useRouter();

  useEffect(() => {
    const endpoint = `${url}?topic=${encodeURIComponent(topic)}`;
    let source: EventSource | null = null;
    let timer: ReturnType<typeof setTimeout> | null = null;

    try {
      source = new EventSource(endpoint, { withCredentials: true });
    } catch {
      return;
    }

    source.onmessage = () => {
      if (timer) clearTimeout(timer);
      timer = setTimeout(() => router.refresh(), 400);
    };
    // A hub that is down or rejects the token just stops the live stream; a
    // manual reload still works, so nothing is surfaced to the operator.
    source.onerror = () => {};

    return () => {
      if (timer) clearTimeout(timer);
      source?.close();
    };
  }, [url, topic, router]);

  return null;
}
