"use client";

import { useEffect } from "react";
import { Button } from "@/components/ui/button";

interface ErrorProps {
  error: Error & { digest?: string };
  reset: () => void;
}

export default function Error({ error, reset }: ErrorProps) {
  useEffect(() => {
    // Wire your error reporter here (the digest identifies the server-side log line).
    console.error(error);
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
