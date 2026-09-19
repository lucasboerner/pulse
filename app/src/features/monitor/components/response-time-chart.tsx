import type { HistoryBucket } from "@/features/monitor/types";

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

// Split the series into runs of consecutive buckets that carry a latency. A gap
// (a bucket with no check) ends a run, so the chart breaks the line there rather
// than interpolating across missing data.
function buildSegments(series: HistoryBucket[], maxLatency: number): Point[][] {
  const count = series.length;
  const span = count > 1 ? count - 1 : 1;
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
    const x = (index / span) * 100;
    const y = BOTTOM - (bucket.medianLatencyMs / maxLatency) * (BOTTOM - TOP);
    current.push({ x, y });
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
// fill, no axes, grid, dots or tooltips. preserveAspectRatio="none" lets it stretch
// with the card while non-scaling strokes keep the line crisp. Gaps break the line.
export function ResponseTimeChart({ series }: ResponseTimeChartProps) {
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

  return (
    <div className="flex flex-col gap-3">
      <svg
        viewBox="0 0 100 100"
        preserveAspectRatio="none"
        className="h-40 w-full"
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
      <div className="flex items-center justify-between font-mono text-[11px] tracking-[0.08em] uppercase text-muted-foreground">
        <span>24H Ago</span>
        <span>Now</span>
      </div>
    </div>
  );
}
