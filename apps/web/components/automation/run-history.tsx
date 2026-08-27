"use client"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge, RunStatusBadge } from "@/components/automation/badges"
import {
  formatDuration,
  formatJakarta,
  type MirrorResult,
  type ScheduledTask,
  type TaskRun,
} from "@/lib/automation-client"

/**
 * What one run actually did.
 *
 * The output shown here was redacted and length-capped before it was stored,
 * so a verbose scrape cannot grow the table without bound and a bearer that
 * leaked into a log line never reaches the browser.
 */
export function RunHistory({
  task,
  runs,
  loading,
}: {
  task: ScheduledTask
  runs: TaskRun[]
  loading: boolean
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Run history — {task.name}</CardTitle>
        <CardDescription>
          Every attempt, including the ones that deliberately did nothing. A skipped run means
          the scheduler fired and the market condition said no; an absent run means it never
          fired at all.
        </CardDescription>
      </CardHeader>

      <CardContent>
        {loading ? (
          <p className="text-sm text-muted-foreground">Loading run history…</p>
        ) : runs.length === 0 ? (
          <p className="text-sm text-muted-foreground">This automation has not run yet.</p>
        ) : (
          <div className="space-y-3">
            {runs.map((run) => (
              <RunRow key={run.id} run={run} command={task.command} />
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function RunRow({ run, command }: { run: TaskRun; command: string }) {
  const range =
    run.range_from && run.range_to
      ? `${run.range_from} → ${run.range_to}`
      : (run.market_date ?? "—")

  return (
    <details className="rounded-lg border p-3">
      <summary className="flex cursor-pointer flex-wrap items-center gap-3 text-sm">
        <RunStatusBadge status={run.status} skipReason={run.skip_reason} partial={run.partial} />
        <span className="font-medium">{formatJakarta(run.scheduled_for ?? run.created_at)}</span>
        <span className="text-muted-foreground">WIB</span>
        {run.trigger === "manual" ? <Badge>Manual</Badge> : null}
        <span className="text-muted-foreground">{range}</span>
        <span className="ml-auto text-muted-foreground">{formatDuration(run.duration_ms)}</span>
      </summary>

      <dl className="mt-3 grid gap-3 border-t pt-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
        <Field label="Command" value={<code className="text-xs">{command}</code>} />
        <Field label="Scheduled for" value={formatJakarta(run.scheduled_for)} />
        <Field label="Started" value={formatJakarta(run.started_at)} />
        <Field label="Finished" value={formatJakarta(run.finished_at)} />
        <Field label="Duration" value={formatDuration(run.duration_ms)} />
        <Field label="Exit code" value={run.exit_code === null ? "—" : String(run.exit_code)} />
        <Field label="Market date / range" value={range} />
        <Field
          label="Tickers"
          value={
            run.ticker_count === null
              ? "—"
              : `${run.success_ticker_count ?? 0} of ${run.ticker_count} succeeded${
                  run.failed_ticker_count ? `, ${run.failed_ticker_count} failed` : ""
                }`
          }
        />
        <Field label="Drive (OHLCV CSVs)" value={<MirrorSummary result={run.gdrive} />} />
        <Field
          label="Drive (broker summary)"
          value={<MirrorSummary result={run.gdrive_broker_summary} />}
        />
      </dl>

      {run.error ? (
        <div className="mt-3">
          <p className="text-xs font-medium uppercase text-muted-foreground">Problem</p>
          <p className="mt-1 rounded-md bg-destructive/10 p-2 text-sm text-destructive">
            {run.error}
          </p>
        </div>
      ) : null}

      {run.output ? (
        <div className="mt-3">
          <p className="text-xs font-medium uppercase text-muted-foreground">Output</p>
          <pre className="mt-1 max-h-72 overflow-auto rounded-md bg-muted p-3 text-xs">
            {run.output}
          </pre>
        </div>
      ) : null}
    </details>
  )
}

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs uppercase text-muted-foreground">{label}</dt>
      <dd className="mt-0.5">{value}</dd>
    </div>
  )
}

function MirrorSummary({ result }: { result: MirrorResult | null }) {
  if (!result) return <span className="text-muted-foreground">—</span>

  if (result.status === "not_configured") {
    return <span className="text-muted-foreground">No mirror disk configured</span>
  }

  if (result.status === "skipped") {
    return <span className="text-muted-foreground">Skipped</span>
  }

  const uploaded = Array.isArray(result.uploaded) ? result.uploaded.length : (result.uploaded ?? 0)
  const failed = Array.isArray(result.failed) ? result.failed : []

  if (result.status === "failed" || failed.length > 0) {
    return (
      <span className="text-destructive">
        {failed.length > 0
          ? `${failed.length} upload(s) failed — the local copies are intact`
          : (result.message ?? "Upload failed — the local copies are intact")}
      </span>
    )
  }

  return (
    <span>
      {uploaded} uploaded
      {result.skipped_unchanged ? `, ${result.skipped_unchanged} already up to date` : ""}
      {result.disk ? ` (${result.disk})` : ""}
    </span>
  )
}
