import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { Incident } from "@/features/monitor/types";
import { IncidentHistoryRow } from "@/features/monitor/components/incident-history-row";

interface IncidentHistoryCardProps {
  incidents: Incident[];
  /** The owning monitor's name, passed down as the detail dialog's title — the
   *  incident payload carries only its UUID. */
  monitorName: string;
  /** The request-time clock, so an ongoing incident's duration agrees on server and
   *  client — threaded in the way MonitorsTable already does. */
  now: number;
}

// The incident history in the visual form of the mock's event log: a vertical list
// of entries split by hairlines, each one a button that opens the incident's detail
// dialog. The rows carry their own padding so the hairlines and the hover fill reach
// the card's edges, as in the Recent Checks table.
export function IncidentHistoryCard({ incidents, monitorName, now }: IncidentHistoryCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Incident History</CardTitle>
        <CardDescription>Outages, most recent first.</CardDescription>
      </CardHeader>
      <CardContent className="px-0">
        {incidents.length === 0 ? (
          <p className="px-4 py-2 text-[13px] text-muted-foreground sm:px-5">No incidents recorded.</p>
        ) : (
          <ul className="flex flex-col">
            {incidents.map((incident) => (
              <li key={incident.id} className="border-b border-border last:border-b-0">
                <IncidentHistoryRow incident={incident} monitorName={monitorName} now={now} />
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
