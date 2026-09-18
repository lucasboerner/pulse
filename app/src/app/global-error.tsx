"use client";

import { useEffect } from "react";

// Catches errors thrown by the root layout itself, so it must render its own
// <html>/<body> — the layout that would normally provide them has failed. Styles
// are inline for the same reason: globals.css is imported by that layout.
export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <html lang="en">
      <body
        style={{
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          height: "100vh",
          margin: 0,
          fontFamily: "system-ui, sans-serif",
        }}
      >
        <div style={{ textAlign: "center", gap: 16, display: "flex", flexDirection: "column" }}>
          <h2 style={{ fontSize: 20, margin: 0 }}>Something went wrong.</h2>
          <button
            onClick={reset}
            style={{
              padding: "8px 20px",
              cursor: "pointer",
              background: "#171717",
              color: "#fafafa",
              border: 0,
              borderRadius: 6,
              fontSize: 14,
              fontFamily: "inherit",
            }}
          >
            Try again
          </button>
        </div>
      </body>
    </html>
  );
}
