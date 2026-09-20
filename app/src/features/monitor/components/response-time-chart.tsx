"use client";

import { useState } from "react";

import type { HistoryBucket } from "@/features/monitor/types";
import { formatUtcTime } from "@/features/monitor/lib/status";
import { ChartTooltip } from "@/features/monitor/components/chart-tooltip";

interface ResponseTimeChartProps {
  series: HistoryBucket[];
}

// The drawable band inside the 100×100 viewBox, leaving headroom top and bottom so
// the line and the baseline fill never touch the edges.
const TOP = 8;
const BOTTOM = 92;
const BASELINE = BOTTOM;

interface Point {
  x: number;
  y: number;
}

// The x divisor: the last bucket sits at x=100, so N buckets make N-1 steps. A
// lone bucket would divide by zero, so it anchors at x=0 instead.
function xSpan(count: number): number {
  return count > 1 ? count - 1 : 1;
}

function yFor(latencyMs: number, maxLatency: number): number {
  return BOTTOM - (latencyMs / maxLatency) * (BOTTOM - TOP);
}

// Split the series into runs of consecutive buckets that carry a latency. A gap
// (a bucket with no check) ends a run, so the chart breaks the line there rather
// than interpolating across missing data.
function buildSegments(series: HistoryBucket[], maxLatency: number): Point[][] {
  const span = xSpan(series.length);
  const segments: Point[][] = [];
  let current: Point[] = [];

  series.forEach((bucket, index) => {
    if (bucket.medianLatencyMs == null) {
      if (current.length > 0) {
        segments.push(current);
        current = [];
      }
      return;
    }
    current.push({ x: (index / span) * 100, y: yFor(bucket.medianLatencyMs, maxLatency) });
  });

  if (current.length > 0) segments.push(current);
  return segments;
}

function linePath(points: Point[]): string {
  return points
    .map((point, index) => `${index === 0 ? "M" : "L"} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`)
    .join(" ");
}

function areaPath(points: Point[]): string {
  const first = points[0];
  const last = points[points.length - 1];
  return `M ${first.x.toFixed(2)} ${BASELINE} ${linePath(points).slice(2)} L ${last.x.toFixed(2)} ${BASELINE} Z`;
}

// A single-series area chart drawn as inline SVG: one primary line, a flat 16%
// fill, no axes, grid or dots. preserveAspectRatio="none" lets it stretch with the
// card while non-scaling strokes keep the line crisp. Gaps break the line.
//
// Hovering snaps to the nearest bucket and floats its time and median latency over
// the chart. The crosshair and dot are HTML, not SVG: the viewBox is stretched to
// the card's aspect ratio, which would squash a circle into an ellipse — but that
// same stretch makes viewBox units map 1:1 onto percentages of the wrapper.
export function ResponseTimeChart({ series }: ResponseTimeChartProps) {
  const [hovered, setHovered] = useState<number | null>(null);

  const latencies = series
    .map((bucket) => bucket.medianLatencyMs)
    .filter((value): value is number => value != null);

  if (latencies.length === 0) {
    return (
      <p className="py-10 text-[13px] text-muted-foreground">
        No response times in the last 24 hours.
      </p>
    );
  }

  const maxLatency = Math.max(...latencies, 1);
  const segments = buildSegments(series, maxLatency);
  const span = xSpan(series.length);

  const activeBucket = hovered == null ? null : (series[hovered] ?? null);
  const activeX = hovered == null ? 0 : (hovered / span) * 100;
  const activeY =
    activeBucket?.medianLatencyMs == null ? null : yFor(activeBucket.medianLatencyMs, maxLatency);

  function trackPointer(event: React.PointerEvent<HTMLDivElement>) {
    const { left, width } = event.currentTarget.getBoundingClientRect();
    if (width === 0) return;
    const index = Math.round(((event.clientX - left) / width) * span);
    setHovered(Math.min(series.length - 1, Math.max(0, index)));
  }

  return (
    <div className="flex flex-col gap-3">
      <div
        className="relative h-40 w-full"
        onPointerMove={trackPointer}
        onPointerLeave={() => setHovered(null)}
      >
        <svg
          viewBox="0 0 100 100"
          preserveAspectRatio="none"
          className="h-full w-full"
          role="img"
          aria-label="Median response time over the last 24 hours"
        >
          {segments.map((points, index) => (
            <path
              key={`area-${index}`}
              d={areaPath(points)}
              className="fill-primary"
              fillOpacity={0.16}
            />
          ))}
          {segments.map((points, index) => (
            <path
              key={`line-${index}`}
              d={linePath(points)}
              fill="none"
              className="stroke-primary"
              strokeWidth={1.5}
              strokeLinejoin="round"
              strokeLinecap="round"
              vectorEffect="non-scaling-stroke"
            />
          ))}
        </svg>

        {activeBucket && (
          <>
            <span
              aria-hidden
              className="pointer-events-none absolute w-px -translate-x-1/2 bg-border"
              style={{ left: `${activeX}%`, top: `${TOP}%`, height: `${BOTTOM - TOP}%` }}
            />
            {activeY != null && (
              <span
                aria-hidden
                className="pointer-events-none absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary ring-2 ring-card"
                style={{ left: `${activeX}%`, top: `${activeY}%` }}
              />
            )}
            <div
              className="pointer-events-none absolute inset-x-0"
              style={{ top: `${activeY ?? TOP}%` }}
            >
              <ChartTooltip
                at={activeX / 100}
                label={formatUtcTime(activeBucket.bucketStart)}
                value={
                  activeBucket.medianLatencyMs == null
                    ? "No check"
                    : `${activeBucket.medianLatencyMs} ms`
                }
              />
            </div>
          </>
        )}
      </div>

      <div className="flex items-center justify-between font-mono text-[11px] tracking-[0.08em] uppercase text-muted-foreground">
        <span>24H Ago</span>
        <span>Now</span>
      </div>
    </div>
  );
}
