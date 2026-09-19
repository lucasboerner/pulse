import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import type { IncidentSummary } from "@/features/monitor/types";
import { formatUtcTimestamp } from "@/features/monitor/lib/status";

interface OverviewIncidentsCardProps {
  incidents: IncidentSummary[];
}

interface IncidentState {
  label: string;
  variant: "destructive" | "warning" | "success";
}

// An open incident carries its live severity; a closed one always reads as
// resolved. `endedAt` null (or absent) is the open marker — compare loosely.
function incidentState(incident: IncidentSummary): IncidentState {
  if (incident.endedAt == null) {
    return incident.severity === "down"
      ? { label: "Ongoing", variant: "destructive" }
      : { label: "Degraded", variant: "warning" };
  }
  return { label: "Resolved", variant: "success" };
}

// The overview's recent-incidents feed: each entry is the monitor's name, a state
// badge, and a muted detail line of the cause and the incident's start time.
export function OverviewIncidentsCard({ incidents }: OverviewIncidentsCardProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Recent Incidents</CardTitle>
        <CardDescription>Newest outages first.</CardDescription>
      </CardHeader>
      <CardContent>
        {incidents.length === 0 ? (
          <p className="py-2 text-[13px] text-muted-foreground">No incidents recorded.</p>
        ) : (
          <ul className="flex flex-col">
            {incidents.map((incident, index) => {
              const state = incidentState(incident);
              const when = formatUtcTimestamp(incident.startedAt);
              const detail = incident.cause ? `${incident.cause} · ${when}` : when;

              return (
                <li
                  key={`${incident.monitorName}-${incident.startedAt}-${index}`}
                  className="flex items-start justify-between gap-3 border-b border-border py-2.75 last:border-b-0"
                >
                  <div className="flex min-w-0 flex-col gap-0.5">
                    <span className="truncate text-[13px] font-medium">{incident.monitorName}</span>
                    <span className="truncate text-[11px] text-muted-foreground" title={detail}>
                      {detail}
                    </span>
                  </div>
                  <Badge variant={state.variant} dot>
                    {state.label}
                  </Badge>
                </li>
              );
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
