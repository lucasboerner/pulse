import Link from "next/link";

import { Button } from "@/components/ui/button";

// Rendered when the monitor read 404s — an unknown or soft-deleted id. Empty-state
// language: one muted sentence naming the cause and one outline button out.
export default function MonitorNotFound() {
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-4 px-6 py-24 text-center">
      <p className="text-[13px] text-muted-foreground">
        This monitor does not exist, or it was deleted.
      </p>
      <Button asChild variant="outline" size="sm">
        <Link href="/monitors">Back to Monitors</Link>
      </Button>
    </div>
  );
}
