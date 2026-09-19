"use client";

import { useOptimistic, useTransition } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { setMonitorSubscriptionAction } from "@/lib/api/actions";
import type { InstanceUser } from "@/features/monitor/types";

interface MonitorSubscribersRowProps {
  monitorId: string;
  subscribers: string[];
  users: InstanceUser[];
  currentUserId: string | null;
}

// The ALERTS row value: the recipients' e-mail addresses, and beneath them a toggle
// for the signed-in operator alone to add or remove themselves. Zero recipients is
// a legal, visible state (a monitor still checks and still opens incidents, it just
// mails nobody) — shown in the destructive tone at normal weight, never as an alarm.
export function MonitorSubscribersRow({
  monitorId,
  subscribers,
  users,
  currentUserId,
}: MonitorSubscribersRowProps) {
  const [pending, startTransition] = useTransition();
  const [optimisticSubscribers, setOptimisticSubscribers] = useOptimistic(subscribers);

  const emails = optimisticSubscribers
    .map((id) => users.find((user) => user.id === id)?.email)
    .filter((email): email is string => Boolean(email));
  const subscribed = currentUserId !== null && optimisticSubscribers.includes(currentUserId);

  function onToggle() {
    if (currentUserId === null) return;
    const next = subscribed
      ? optimisticSubscribers.filter((id) => id !== currentUserId)
      : [...optimisticSubscribers, currentUserId];

    startTransition(async () => {
      setOptimisticSubscribers(next);
      const result = await setMonitorSubscriptionAction(
        monitorId,
        currentUserId,
        !subscribed,
        optimisticSubscribers,
      );
      if (result.error) {
        toast.error(result.error);
        return;
      }
      toast(subscribed ? "You will no longer be alerted." : "You will be alerted.");
    });
  }

  return (
    <div className="flex flex-col items-end gap-1.5">
      {emails.length > 0 ? (
        <span className="text-right text-[13px] break-all">{emails.join(", ")}</span>
      ) : (
        <span className="text-right text-[13px] font-normal text-destructive">
          No recipients — this monitor mails nobody.
        </span>
      )}

      {currentUserId !== null ? (
        <Button variant="ghost" size="xs" onClick={onToggle} disabled={pending}>
          {subscribed ? "Unsubscribe me" : "Subscribe me"}
        </Button>
      ) : null}
    </div>
  );
}
