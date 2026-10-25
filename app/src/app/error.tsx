"use client";

import * as Sentry from "@sentry/nextjs";
import { useEffect } from "react";
import { Button } from "@/components/ui/button";

interface ErrorProps {
  error: Error & { digest?: string };
  reset: () => void;
}

export default function Error({ error, reset }: ErrorProps) {
  useEffect(() => {
    console.error(error);
    // A digest marks an error thrown on the server, which onRequestError already
    // reported (instrumentation.ts) — the browser only sees its redacted copy. Report
    // what failed in the browser. A no-op while Sentry is off.
    if (!error.digest) Sentry.captureException(error);
  }, [error]);

  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-4 px-6 py-16 text-center">
      <h1 className="text-xl font-semibold">Something went wrong.</h1>
      <p className="text-sm text-muted-foreground">
        An unexpected error occurred while rendering this page.
      </p>
      <Button onClick={reset}>Try again</Button>
    </main>
  );
}
