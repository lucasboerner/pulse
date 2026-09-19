import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { Incident } from "@/features/monitor/types";
import { formatDuration, formatUtcTimestamp } from "@/features/monitor/lib/status";
import { StatusDot } from "@/features/monitor/components/status-dot";

interface IncidentHistoryCardProps {
  incidents: Incident[];
  /** The request-time clock, so an ongoing incident's duration agrees on server and
   *  client — threaded in the way MonitorsTable already does. */
  now: number;
}

// The incident history in the visual form of the mock's event log: a vertical list
// of entries, each a severity-coloured dot, one line of text and a muted timestamp
// line, split by hairlines. An ongoing incident is measured against the render clock.
export function IncidentHistoryCard({ incidents, now }: IncidentHistoryCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Incident History</CardTitle>
        <CardDescription>Outages, most recent first.</CardDescription>
      </CardHeader>
      <CardContent>
        {incidents.length === 0 ? (
          <p className="py-2 text-[13px] text-muted-foreground">No incidents recorded.</p>
        ) : (
          <ul className="flex flex-col">
            {incidents.map((incident) => {
              const started = new Date(incident.startedAt).getTime();
              // An ongoing incident omits endedAt from the payload, so it is undefined
              // rather than null — match loosely.
              const ongoing = incident.endedAt == null;
              const ended = ongoing ? now : new Date(incident.endedAt as string).getTime();
              const duration = formatDuration((ended - started) / 1000);

              const headline = ongoing
                ? `Ongoing — ${incident.severity} for ${duration}`
                : `Resolved after ${duration}`;
              const timestamps = ongoing
                ? `Started ${formatUtcTimestamp(incident.startedAt)}`
                : `${formatUtcTimestamp(incident.startedAt)} → ${formatUtcTimestamp(incident.endedAt)}`;

              return (
                <li
                  key={incident.id}
                  className="flex items-start gap-3 border-b border-border py-2.75 last:border-b-0"
                >
                  <StatusDot status={incident.severity} className="mt-1.5" />
                  <div className="flex min-w-0 flex-col gap-0.5">
                    <span className="text-[13px]">{headline}</span>
                    <span className="text-[11px] tabular-nums text-muted-foreground">
                      {timestamps}
                    </span>
                    {incident.cause ? (
                      <span className="truncate text-[11px] text-muted-foreground" title={incident.cause}>
                        {incident.cause}
                      </span>
                    ) : null}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
