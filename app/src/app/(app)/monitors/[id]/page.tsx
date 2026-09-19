import Link from "next/link";
import { ArrowLeftIcon } from "lucide-react";

import { fetchAll, fetchPage, fetchResource } from "@/lib/api/client";
import { getCurrentUsername } from "@/lib/auth";
import type { Incident, InstanceUser, Monitor, MonitorHistory } from "@/features/monitor/types";
import { intervalLabel, relativeTime } from "@/features/monitor/lib/status";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { PageHeader } from "@/features/shell/components/page-header";
import { NewMonitorButton } from "@/features/monitor/components/new-monitor-button";
import { MonitorDetailLive } from "@/features/monitor/components/monitor-detail-live";
import { MonitorDetailStats } from "@/features/monitor/components/monitor-detail-stats";
import { ResponseTimeChart } from "@/features/monitor/components/response-time-chart";
import { RecentChecksTable } from "@/features/monitor/components/recent-checks-table";
import { MonitorConfigurationCard } from "@/features/monitor/components/monitor-configuration-card";
import { IncidentHistoryCard } from "@/features/monitor/components/incident-history-card";

interface MonitorDetailPageProps {
  params: Promise<{ id: string }>;
}

// The monitor detail page: one Server Component reads the monitor, its aggregate
// history, its newest incidents and the operator list in parallel. A missing or
// soft-deleted monitor renders not-found.tsx (notFoundOn404 on the reads that
// 404). No client fetching — the live surface flips in place and refreshes the read.
export default async function MonitorDetailPage({ params }: MonitorDetailPageProps) {
  const { id } = await params;

  const [monitor, history, incidentsPage, { data: users }, username] = await Promise.all([
    fetchResource<Monitor>(`/api/monitors/${id}`, { notFoundOn404: true }),
    fetchResource<MonitorHistory>(`/api/monitors/${id}/history`, { notFoundOn404: true }),
    fetchPage<Incident>("/api/incidents", 1, { monitor: id, itemsPerPage: "10" }),
    fetchAll<InstanceUser>("/api/users"),
    getCurrentUsername(),
  ]);

  const currentUserId = users.find((user) => user.username === username)?.id ?? null;
  // A Server Component renders once per request, so a single request-time clock is
  // stable; it is threaded into the client and list components so SSR and hydration
  // match and an ongoing incident's duration agrees.
  // eslint-disable-next-line react-hooks/purity
  const now = Date.now();

  const incidents = incidentsPage.data;
  // An ongoing incident omits endedAt entirely (a null attribute is dropped from the
  // JSON:API payload), so it reads as undefined — match it loosely.
  const openIncident = incidents.find((incident) => incident.endedAt == null);
  const causeLine = openIncident?.cause ?? null;

  const updatedLabel = monitor.lastCheckedAt
    ? `checked ${relativeTime(monitor.lastCheckedAt, now)}`
    : "not checked yet";

  return (
    <>
      <PageHeader
        title={monitor.name}
        subtitle={`HTTP check · every ${intervalLabel(monitor.intervalSeconds)}`}
        updatedLabel={updatedLabel}
        leading={
          <Button asChild variant="ghost" size="icon-sm" aria-label="Back">
            <Link href="/monitors">
              <ArrowLeftIcon />
            </Link>
          </Button>
        }
        action={<NewMonitorButton users={users} currentUserId={currentUserId} />}
      />

      <div className="flex flex-col gap-5 p-6">
        <MonitorDetailLive
          monitor={monitor}
          users={users}
          currentUserId={currentUserId}
          causeLine={causeLine}
        />

        <MonitorDetailStats history={history} />

        <div className="grid grid-cols-1 gap-5 min-[1100px]:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
          <div className="flex min-w-0 flex-col gap-5">
            <Card>
              <CardHeader>
                <CardTitle>Response Time</CardTitle>
                <CardDescription>Median latency, last 24h</CardDescription>
              </CardHeader>
              <CardContent>
                <ResponseTimeChart series={history.series} />
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Recent Checks</CardTitle>
                <CardDescription>The latest probe results</CardDescription>
              </CardHeader>
              <CardContent className="px-0">
                <RecentChecksTable checks={history.recentChecks} now={now} />
              </CardContent>
            </Card>
          </div>

          <div className="flex min-w-0 flex-col gap-5">
            <MonitorConfigurationCard
              monitor={monitor}
              users={users}
              currentUserId={currentUserId}
            />
            <IncidentHistoryCard incidents={incidents} now={now} />
          </div>
        </div>
      </div>
    </>
  );
}
