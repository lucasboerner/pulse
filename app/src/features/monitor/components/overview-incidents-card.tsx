import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import type { IncidentSummary } from "@/features/monitor/types";
import { OverviewIncidentRow } from "@/features/monitor/components/overview-incident-row";

interface OverviewIncidentsCardProps {
  incidents: IncidentSummary[];
  /** The request-time clock, so an incident's duration in the dialog agrees on
   *  server and client — threaded in the way MonitorsTable already does. */
  now: number;
}

// The overview's recent-incidents feed. The rows carry their own padding so the
// hairlines and the hover fill reach the card's edges, as in the All Systems list;
// each one opens the incident's detail dialog.
export function OverviewIncidentsCard({ incidents, now }: OverviewIncidentsCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Recent Incidents</CardTitle>
        <CardDescription>Newest outages first.</CardDescription>
      </CardHeader>
      <CardContent className="px-0">
        {incidents.length === 0 ? (
          <p className="px-4 py-2 text-[13px] text-muted-foreground sm:px-5">No incidents recorded.</p>
        ) : (
          <ul className="flex flex-col">
            {incidents.map((incident, index) => (
              <li
                key={`${incident.monitorId}-${incident.startedAt}-${index}`}
                className="border-b border-border last:border-b-0"
              >
                <OverviewIncidentRow incident={incident} now={now} />
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
