"use client"

import { useEffect, useMemo, useState } from "react"
import {
  Activity,
  ArrowDownUp,
  CheckCircle2,
  ChevronDown,
  CircleAlert,
  CloudUpload,
  Database,
  FileJson,
  Search,
  ShieldCheck,
  TriangleAlert,
  X,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { formatBytes, formatTime } from "@/components/backup-status"
import type {
  FlowRow,
  FlowSnapshot,
  IntegrityStatus,
  ReadinessReport,
  ReadinessStatus,
} from "@/lib/backup-client"
import {
  flowValue,
  type ReconciliationDetail,
  type ReconciliationFilter,
  type ReconciliationList,
  type ReconciliationQuery,
  type ReconciliationRow,
  type ReconciliationSort,
} from "@/lib/reconciliation-client"

const READINESS_TONE: Record<ReadinessStatus, string> = {
  ready: "border-emerald-500/50",
  degraded: "border-amber-500/50",
  not_ready: "border-destructive/50",
}

const READINESS_LABEL: Record<ReadinessStatus, string> = {
  ready: "Recovery ready",
  degraded: "Recovery ready, with warnings",
  not_ready: "Recovery not ready",
}

const STATUS_TONE: Record<IntegrityStatus, string> = {
  healthy: "text-emerald-700 dark:text-emerald-400",
  warning: "text-amber-700 dark:text-amber-400",
  error: "text-destructive",
}

const STATUS_LABEL: Record<IntegrityStatus, string> = {
  healthy: "Healthy",
  warning: "Warning",
  error: "Error",
}

const dash = "—"

const formatDate = (value: string | null | undefined) => value ?? dash

const formatPercent = (value: number | null | undefined) =>
  typeof value === "number"
    ? `${value >= 0 ? "+" : ""}${(value * 100).toFixed(2)}%`
    : dash

const formatSigned = (value: number | null | undefined) =>
  typeof value === "number" ? `${value > 0 ? "+" : ""}${value}` : dash

const formatCount = (value: number | null | undefined) =>
  typeof value === "number" ? new Intl.NumberFormat().format(value) : dash

export function IntegrityBadge({ status }: { status: IntegrityStatus }) {
  return (
    <span className={`inline-flex items-center gap-1.5 whitespace-nowrap ${STATUS_TONE[status]}`}>
      {status === "healthy" ? (
        <CheckCircle2 className="size-4" />
      ) : status === "warning" ? (
        <TriangleAlert className="size-4" />
      ) : (
        <CircleAlert className="size-4" />
      )}
      {STATUS_LABEL[status]}
    </span>
  )
}

/**
 * The verdict, and the conditions that produced it.
 *
 * Deliberately conservative: "ready" needs a manifest that exists, describes
 * assets, carries no errors, and matches what cold storage holds. Any one of
 * those failing is a recovery that would not complete, so listing the reason
 * matters more than the colour.
 */
export function ReadinessBanner({ report }: { report: ReadinessReport }) {
  const { status, blockers, warnings } = report.readiness

  return (
    <Card className={READINESS_TONE[status]}>
      <CardContent className="space-y-3 py-5">
        <div className="flex items-start gap-3">
          {status === "ready" ? (
            <ShieldCheck className="size-6 shrink-0 text-emerald-600" />
          ) : status === "degraded" ? (
            <TriangleAlert className="size-6 shrink-0 text-amber-600" />
          ) : (
            <CircleAlert className="size-6 shrink-0 text-destructive" />
          )}
          <div>
            <p className="font-semibold">{READINESS_LABEL[status]}</p>
            <p className="text-sm text-muted-foreground">
              {status === "ready"
                ? `The reconciliation layer describes ${formatCount(report.reconciliation.asset_count)} asset(s) and matches the copy in cold storage.`
                : "The database can be rebuilt only from what the reconciliation layer actually holds. These conditions would interrupt that."}
            </p>
          </div>
        </div>

        {blockers.length > 0 ? (
          <ul className="list-disc space-y-1 pl-9 text-sm text-destructive">
            {blockers.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        ) : null}

        {warnings.length > 0 ? (
          <ul className="list-disc space-y-1 pl-9 text-sm text-amber-700 dark:text-amber-400">
            {warnings.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        ) : null}
      </CardContent>
    </Card>
  )
}

function Metric({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="font-medium">{value}</dd>
      {hint ? <dd className="text-xs text-muted-foreground">{hint}</dd> : null}
    </div>
  )
}

/**
 * The three layers, each reported on its own terms.
 *
 * Raw archive, reconciliation layer, and cold-storage mirror are separate
 * cards because a healthy one tells you nothing about the others: a current
 * manifest that was never published is a different problem from a published
 * manifest built from an empty database.
 */
export function ReadinessCards({
  report,
  onPushMirror,
  pushing,
}: {
  report: ReadinessReport
  onPushMirror?: () => void
  pushing?: boolean
}) {
  const { reconciliation, mirror, raw_archive: archive } = report

  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <Card>
        <CardHeader className="pb-3">
          <div className="flex items-center gap-3">
            <Database className="size-6 text-primary" />
            <div>
              <CardTitle className="text-base">Reconciliation layer</CardTitle>
              <CardDescription>
                {reconciliation.present ? "Built" : "Not built yet"}
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <Metric label="Assets" value={formatCount(reconciliation.asset_count)} />
            <Metric label="Market date" value={formatDate(reconciliation.market_date)} />
            <Metric
              label="Health"
              value={`${formatCount(reconciliation.healthy)} healthy`}
              hint={`${formatCount(reconciliation.warning)} warning · ${formatCount(reconciliation.error)} error`}
            />
            <Metric
              label="Coverage gaps"
              value={`${formatCount(reconciliation.with_gaps)} asset(s)`}
              hint="Sessions with a bar but no daily broker summary"
            />
            <Metric
              label="Latest OHLCV"
              value={formatDate(reconciliation.latest_ohlcv_date)}
              hint={`${formatCount(reconciliation.ohlcv_current)} asset(s) at that date`}
            />
            <Metric
              label="Latest daily broker"
              value={formatDate(reconciliation.latest_broker_daily_date)}
              hint={`${formatCount(reconciliation.broker_current)} asset(s) at that date`}
            />
          </dl>
          <p className="mt-4 text-xs text-muted-foreground">
            Rebuilt by <code className="font-mono">php artisan data:reconcile --all</code>. The raw
            broker-summary JSON files remain the source of record; this layer is derived from them
            and never replaces them.
          </p>
        </CardContent>
      </Card>

      <Card className={mirror.enabled && !mirror.in_sync ? "border-amber-500/50" : undefined}>
        <CardHeader className="pb-3">
          <div className="flex items-center gap-3">
            <CloudUpload className="size-6 text-primary" />
            <div>
              <CardTitle className="text-base">Cold-storage mirror</CardTitle>
              <CardDescription>
                {!mirror.enabled
                  ? "Not configured"
                  : !mirror.reachable
                    ? "Unreachable"
                    : mirror.in_sync
                      ? "Published copy matches"
                      : mirror.manifest_present && mirror.local_manifest_hash === null
                        ? // Not "behind" -- the published copy is the one that
                          // survived, and pushing here would destroy it.
                          "Published copy is ahead of this server"
                        : "Published copy is behind"}
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-3">
          <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <Metric label="Disk" value={mirror.disk ?? dash} />
            <Metric
              label="Manifest published"
              value={mirror.manifest_present ? "Yes" : "No"}
              hint={mirror.reachable ? undefined : "Remote state unknown"}
            />
            <Metric
              label="Local manifest"
              value={mirror.local_manifest_hash ? `${mirror.local_manifest_hash.slice(0, 12)}…` : dash}
            />
            <Metric
              label="Published manifest"
              value={mirror.manifest_hash ? `${mirror.manifest_hash.slice(0, 12)}…` : dash}
            />
          </dl>

          {mirror.message ? (
            <p className="text-sm text-muted-foreground">{mirror.message}</p>
          ) : null}

          <p className="text-xs text-muted-foreground">
            Assets are uploaded and verified before the manifest, so a manifest present in cold
            storage always describes documents that are already there. Comparison is by hash; an
            unreachable remote is reported as unknown, never as synchronized.
          </p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-3">
          <div className="flex items-center gap-3">
            <FileJson className="size-6 text-primary" />
            <div>
              <CardTitle className="text-base">Raw archive</CardTitle>
              <CardDescription>
                {archive.mirror_enabled ? "Source of record, mirrored" : "Source of record, local only"}
              </CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-3">
          <p className="text-sm text-muted-foreground">
            Every broker-summary response is kept as the file it arrived in. Reconciliation reads
            those files; it does not consume them, and restoring from the reconciliation layer never
            removes them.
          </p>
          {onPushMirror && archive.mirror_enabled ? (
            <Button variant="outline" onClick={onPushMirror} disabled={pushing}>
              {pushing ? "Pushing…" : "Push raw archive to Drive"}
            </Button>
          ) : (
            <p className="text-sm text-muted-foreground">
              No archive mirror is configured, so <code className="font-mono">{archive.path}</code>{" "}
              exists only on this server.
            </p>
          )}
          <p className="text-xs text-muted-foreground">
            The push enumerates the archive on the server. The browser sends no paths, and each
            upload is read back and compared before it is reported as successful.
          </p>
        </CardContent>
      </Card>
    </div>
  )
}

function FlowList({
  title,
  rows,
  emptyLabel,
  window,
}: {
  title: string
  rows: FlowRow[]
  emptyLabel: string
  window: number
}) {
  return (
    <div>
      <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {title}
      </p>
      {rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">{emptyLabel}</p>
      ) : (
        <ul className="space-y-1.5 text-sm">
          {rows.map((row) => (
            <li key={row.symbol} className="flex items-baseline justify-between gap-3">
              <span className="font-mono font-medium">{row.symbol}</span>
              <span className="text-right text-muted-foreground">
                <span className="font-medium text-foreground">
                  {formatSigned(row.flow_balance)}
                </span>{" "}
                over {row.available_sessions}/{window} session(s) · {formatPercent(row.price_return)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * Descriptive, never a recommendation.
 *
 * The balance sums the accumulation/distribution label over genuine
 * single-day broker sessions, and symbols without a full window are listed as
 * insufficient rather than shown as neutral -- a ranking that quietly includes
 * a symbol with two sessions of data is worse than no ranking.
 */
export function FlowSnapshotCard({ snapshot }: { snapshot: FlowSnapshot }) {
  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-3">
          <Activity className="size-6 text-primary" />
          <div>
            <CardTitle>Broker flow snapshot</CardTitle>
            <CardDescription>
              Last {snapshot.window} single-day sessions · {snapshot.ranked_count} symbol(s) with a
              full window
            </CardDescription>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-5">
        <div className="grid gap-6 md:grid-cols-3">
          <FlowList
            title="Net accumulation"
            rows={snapshot.accumulating}
            emptyLabel="No symbol has a positive balance over the window."
            window={snapshot.window}
          />
          <FlowList
            title="Net distribution"
            rows={snapshot.distributing}
            emptyLabel="No symbol has a negative balance over the window."
            window={snapshot.window}
          />
          <FlowList
            title={`Insufficient daily data (${snapshot.insufficient_count})`}
            rows={snapshot.insufficient}
            emptyLabel="Every symbol has a full window of daily observations."
            window={snapshot.window}
          />
        </div>
        <p className="text-xs text-muted-foreground">{snapshot.note}</p>
      </CardContent>
    </Card>
  )
}

const FILTERS: { value: "" | ReconciliationFilter; label: string }[] = [
  { value: "", label: "All assets" },
  { value: "stale_broker", label: "Broker data trailing" },
  { value: "missing_ohlcv", label: "Missing OHLCV" },
  { value: "with_gaps", label: "Sessions with no broker summary" },
  { value: "accumulating", label: "Accumulating" },
  { value: "distributing", label: "Distributing" },
  { value: "insufficient_daily", label: "Insufficient daily data" },
]

const SORTS: { value: ReconciliationSort; label: string }[] = [
  { value: "symbol", label: "Symbol" },
  { value: "integrity_status", label: "Health" },
  { value: "latest_broker_daily", label: "Latest daily broker" },
  { value: "ohlcv_last", label: "Latest bar" },
  { value: "gap_count", label: "Coverage gaps" },
  { value: "flow_balance", label: "Flow balance" },
  { value: "price_return", label: "Price return" },
  { value: "ohlcv_rows", label: "Stored bars" },
]

function Select<T extends string>({
  label,
  value,
  options,
  onChange,
}: {
  label: string
  value: T
  options: { value: T; label: string }[]
  onChange: (value: T) => void
}) {
  return (
    <label className="flex flex-col gap-1 text-xs text-muted-foreground">
      {label}
      <select
        value={value}
        onChange={(event) => onChange(event.target.value as T)}
        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm text-foreground shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  )
}

/**
 * The per-asset index, read from the manifest alone.
 *
 * Filtering, sorting and paging happen server-side against that one file, so
 * the table stays a single read no matter how many symbols the universe
 * carries.
 */
export function ReconciliationTable({
  list,
  query,
  loading,
  selected,
  onQueryChange,
  onSelect,
}: {
  list: ReconciliationList
  query: ReconciliationQuery
  loading: boolean
  selected: string | null
  onQueryChange: (query: ReconciliationQuery) => void
  onSelect: (symbol: string | null) => void
}) {
  const [search, setSearch] = useState(query.search ?? "")

  // Debounced so a five-character symbol is one request, not five.
  useEffect(() => {
    const timer = setTimeout(() => {
      if ((query.search ?? "") !== search) {
        onQueryChange({ ...query, search, page: 1 })
      }
    }, 300)

    return () => clearTimeout(timer)
  }, [search, query, onQueryChange])

  const window = list.flow_window
  const from = list.total === 0 ? 0 : (list.page - 1) * list.per_page + 1
  const to = Math.min(list.total, list.page * list.per_page)

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <CardTitle>Asset reconciliation</CardTitle>
            <CardDescription>
              {formatCount(list.total)} asset(s) in the manifest · generated{" "}
              {formatTime(list.generated_at)}
            </CardDescription>
          </div>
          <div className="flex flex-wrap items-end gap-3">
            <label className="flex flex-col gap-1 text-xs text-muted-foreground">
              Symbol
              <div className="relative">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(event) => setSearch(event.target.value.toUpperCase())}
                  placeholder="BBCA"
                  className="h-9 w-36 pl-8 font-mono"
                />
              </div>
            </label>
            <Select
              label="Health"
              value={query.status ?? ""}
              options={[
                { value: "", label: "Any" },
                { value: "healthy", label: "Healthy" },
                { value: "warning", label: "Warning" },
                { value: "error", label: "Error" },
              ]}
              onChange={(value) =>
                onQueryChange({
                  ...query,
                  status: value === "" ? undefined : (value as IntegrityStatus),
                  page: 1,
                })
              }
            />
            <Select
              label="Filter"
              value={query.filter ?? ""}
              options={FILTERS}
              onChange={(value) =>
                onQueryChange({
                  ...query,
                  filter: value === "" ? undefined : (value as ReconciliationFilter),
                  page: 1,
                })
              }
            />
            <Select
              label="Sort by"
              value={query.sort ?? "symbol"}
              options={SORTS}
              onChange={(value) => onQueryChange({ ...query, sort: value, page: 1 })}
            />
            <Button
              variant="outline"
              size="sm"
              className="h-9"
              onClick={() =>
                onQueryChange({
                  ...query,
                  direction: query.direction === "desc" ? "asc" : "desc",
                  page: 1,
                })
              }
            >
              <ArrowDownUp className="size-4" />
              {query.direction === "desc" ? "Descending" : "Ascending"}
            </Button>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full min-w-[980px] text-sm">
            <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="px-4 py-3">Symbol</th>
                <th className="px-4 py-3">Health</th>
                <th className="px-4 py-3">OHLCV</th>
                <th className="px-4 py-3">Daily broker</th>
                <th className="px-4 py-3">Gaps</th>
                <th className="px-4 py-3">Flow {window}d</th>
                <th className="px-4 py-3">Return {window}d</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className={`divide-y ${loading ? "opacity-50" : ""}`}>
              {list.rows.map((row) => (
                <ReconciliationRowView
                  key={row.symbol}
                  row={row}
                  window={window}
                  selected={selected === row.symbol}
                  onSelect={onSelect}
                />
              ))}
            </tbody>
          </table>
          {list.rows.length === 0 ? (
            <p className="p-8 text-center text-muted-foreground">
              {list.total === 0 && !query.search && !query.filter && !query.status
                ? "No reconciliation manifest has been built yet. Run php artisan data:reconcile --all."
                : "No asset matches these filters."}
            </p>
          ) : null}
        </div>

        <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
          <span>
            {from}–{to} of {formatCount(list.total)}
          </span>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={list.page <= 1 || loading}
              onClick={() => onQueryChange({ ...query, page: list.page - 1 })}
            >
              Previous
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={list.page >= list.last_page || loading}
              onClick={() => onQueryChange({ ...query, page: list.page + 1 })}
            >
              Next
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function ReconciliationRowView({
  row,
  window,
  selected,
  onSelect,
}: {
  row: ReconciliationRow
  window: number
  selected: boolean
  onSelect: (symbol: string | null) => void
}) {
  const balance = flowValue(row, "flow_balance", window)
  const available = flowValue(row, "available_daily_sessions", window) ?? 0
  const priceReturn = flowValue(row, "price_return", window)
  const enough = available >= window

  return (
    <tr className={selected ? "bg-muted/40" : "hover:bg-muted/30"}>
      <td className="px-4 py-3 font-mono font-medium">{row.symbol}</td>
      <td className="px-4 py-3">
        <IntegrityBadge status={row.integrity_status} />
        {row.error_count + row.warning_count > 0 ? (
          <div className="text-xs text-muted-foreground">
            {row.error_count} error(s) · {row.warning_count} warning(s)
          </div>
        ) : null}
      </td>
      <td className="px-4 py-3 text-muted-foreground">
        <div>{formatDate(row.ohlcv_last)}</div>
        <div className="text-xs">
          {formatCount(row.ohlcv_rows)} bars
          {row.ohlcv_source_exists ? "" : " · CSV missing"}
        </div>
      </td>
      <td className="px-4 py-3 text-muted-foreground">
        <div>{formatDate(row.latest_broker_daily)}</div>
        <div className="text-xs">
          {formatCount(row.broker_daily_windows)} daily
          {row.broker_aggregate_windows > 0
            ? ` · ${formatCount(row.broker_aggregate_windows)} range`
            : ""}
          {typeof row.broker_lag_sessions === "number" && row.broker_lag_sessions > 0
            ? ` · trails ${row.broker_lag_sessions} session(s)`
            : ""}
        </div>
      </td>
      <td className="px-4 py-3 text-muted-foreground">{formatCount(row.gap_count)}</td>
      <td className="px-4 py-3">
        {enough ? (
          <>
            <span className="font-medium">{formatSigned(balance)}</span>
            <div className="text-xs text-muted-foreground">
              {available}/{window} sessions
            </div>
          </>
        ) : (
          <span
            className="text-xs text-muted-foreground"
            title="Fewer genuine single-day sessions than the window, so a balance would not mean what it appears to."
          >
            {available}/{window} sessions
          </span>
        )}
      </td>
      <td className="px-4 py-3 text-muted-foreground">{formatPercent(priceReturn)}</td>
      <td className="px-4 py-3 text-right">
        <Button
          variant="outline"
          size="sm"
          onClick={() => onSelect(selected ? null : row.symbol)}
        >
          {selected ? "Hide" : "Inspect"}
        </Button>
      </td>
    </tr>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {title}
      </p>
      {children}
    </div>
  )
}

/**
 * One asset's document, summarised.
 *
 * The document itself carries every bar and every broker entry the asset has;
 * the API returns bounded slices of those rather than the whole series,
 * because a fifteen-year history is megabytes and the reader wants coverage,
 * health and the recent trajectory.
 */
export function AssetReconciliationDetail({
  detail,
  onClose,
}: {
  detail: ReconciliationDetail
  onClose: () => void
}) {
  const { coverage, integrity } = detail

  const conditions = useMemo(
    () => [...integrity.errors.map((t) => ({ tone: "error" as const, text: t })),
      ...integrity.warnings.map((t) => ({ tone: "warning" as const, text: t }))],
    [integrity.errors, integrity.warnings],
  )

  return (
    <Card>
      <CardHeader>
        <div className="flex items-start justify-between gap-4">
          <div>
            <CardTitle className="font-mono">{detail.symbol}</CardTitle>
            <CardDescription>
              {detail.asset.name ?? "Unnamed asset"}
              {detail.asset.sector ? ` · ${detail.asset.sector}` : ""} · document{" "}
              {formatBytes(detail.document_size)} · built {formatTime(detail.generated_at)}
            </CardDescription>
          </div>
          <Button variant="outline" size="sm" onClick={onClose}>
            <X className="size-4" /> Close
          </Button>
        </div>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="grid gap-6 md:grid-cols-3">
          <Section title="OHLCV coverage">
            <dl className="space-y-1 text-sm">
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Range</dt>
                <dd>
                  {formatDate(coverage.ohlcv.first_date)} → {formatDate(coverage.ohlcv.last_date)}
                </dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Stored bars</dt>
                <dd>{formatCount(coverage.ohlcv.rows)}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Historical CSV</dt>
                <dd>
                  {coverage.ohlcv.source_exists
                    ? formatBytes(coverage.ohlcv.source_size)
                    : "Missing"}
                </dd>
              </div>
            </dl>
          </Section>

          <Section title="Broker summary coverage">
            <dl className="space-y-1 text-sm">
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Range</dt>
                <dd>
                  {formatDate(coverage.broker_summary.first_window_from)} →{" "}
                  {formatDate(coverage.broker_summary.last_window_to)}
                </dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Single-day windows</dt>
                <dd>{formatCount(coverage.broker_summary.single_day_window_count)}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Range aggregates</dt>
                <dd>{formatCount(coverage.broker_summary.aggregate_window_count)}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Latest single day</dt>
                <dd>{formatDate(coverage.broker_summary.latest_single_day)}</dd>
              </div>
            </dl>
          </Section>

          <Section title="Integrity">
            <div className="space-y-2 text-sm">
              <IntegrityBadge status={integrity.status} />
              {conditions.length === 0 ? (
                <p className="text-muted-foreground">
                  No warnings or errors were recorded for this asset.
                </p>
              ) : (
                <ul className="list-disc space-y-1 pl-5">
                  {conditions.map((condition) => (
                    <li
                      key={condition.text}
                      className={
                        condition.tone === "error"
                          ? "text-destructive"
                          : "text-amber-700 dark:text-amber-400"
                      }
                    >
                      {condition.text}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </Section>
        </div>

        {integrity.missing_broker_sessions.length > 0 ? (
          <Section
            title={`Sessions with a bar but no daily broker summary (${integrity.missing_broker_session_count})`}
          >
            <p className="font-mono text-xs text-muted-foreground">
              {integrity.missing_broker_sessions.join(", ")}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">
              Anchored on this asset&rsquo;s own bars, so a weekend, a holiday or a suspension is
              never reported as a gap.
            </p>
          </Section>
        ) : null}

        {integrity.missing_source_files.length > 0 ? (
          <Section title={`Broker windows whose raw file is no longer in the archive`}>
            <p className="font-mono text-xs text-muted-foreground">
              {integrity.missing_source_files.join(", ")}
            </p>
          </Section>
        ) : null}

        <div className="grid gap-6 lg:grid-cols-2">
          <Section title="Recent daily flow">
            {detail.recent_daily_flow.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No genuine single-day broker sessions are stored for this asset.
              </p>
            ) : (
              <div className="overflow-x-auto rounded-lg border">
                <table className="w-full min-w-[420px] text-sm">
                  <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                      <th className="px-3 py-2">Date</th>
                      <th className="px-3 py-2">Label</th>
                      <th className="px-3 py-2">Score</th>
                      <th className="px-3 py-2">Avg price</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {[...detail.recent_daily_flow].reverse().map((row) => (
                      <tr key={row.date}>
                        <td className="px-3 py-2 font-mono text-xs">{row.date}</td>
                        <td className="px-3 py-2">{row.broker_accdist ?? dash}</td>
                        <td className="px-3 py-2">{formatSigned(row.accdist_score)}</td>
                        <td className="px-3 py-2 text-muted-foreground">
                          {row.average_price === null ? dash : formatCount(row.average_price)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Section>

          <Section title="Recent broker windows">
            {detail.recent_windows.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No broker-summary windows are stored for this asset.
              </p>
            ) : (
              <div className="overflow-x-auto rounded-lg border">
                <table className="w-full min-w-[460px] text-sm">
                  <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                      <th className="px-3 py-2">Window</th>
                      <th className="px-3 py-2">Kind</th>
                      <th className="px-3 py-2">Entries</th>
                      <th className="px-3 py-2">Raw file</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {[...detail.recent_windows].reverse().map((row) => (
                      <tr key={`${row.from_date}-${row.to_date}-${row.transaction_type}`}>
                        <td className="px-3 py-2 font-mono text-xs">
                          {row.from_date === row.to_date
                            ? row.from_date
                            : `${row.from_date} → ${row.to_date}`}
                        </td>
                        <td className="px-3 py-2 text-muted-foreground">
                          {row.is_single_day ? "Single day" : "Range aggregate"}
                        </td>
                        <td className="px-3 py-2 text-muted-foreground">
                          {formatCount(row.entry_count)}
                        </td>
                        <td
                          className="max-w-[220px] truncate px-3 py-2 font-mono text-xs text-muted-foreground"
                          title={row.source_filename ?? undefined}
                        >
                          {row.source_filename ?? dash}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <p className="mt-2 text-xs text-muted-foreground">
              A range aggregate covers its whole window and is never split into individual days.
              Only genuine single-day windows contribute to the flow balance.
            </p>
          </Section>
        </div>

        <p className="text-xs text-muted-foreground">
          Restore this asset with{" "}
          <code className="font-mono">php artisan data:restore --symbol={detail.symbol}</code>.
          Document hash{" "}
          <span className="font-mono">{detail.document_hash?.slice(0, 16) ?? dash}…</span>
        </p>
      </CardContent>
    </Card>
  )
}

/** The forensic comparison, collapsed until asked for. */
export function DeepAuditPanel({
  running,
  hasReport,
  onRun,
  children,
}: {
  running: boolean
  hasReport: boolean
  onRun: () => void
  children: React.ReactNode
}) {
  const [open, setOpen] = useState(false)

  return (
    <Card>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="flex w-full items-center justify-between gap-2 px-6 py-4 text-left text-sm font-medium"
      >
        <span>
          File-by-file audit
          <span className="ml-2 font-normal text-muted-foreground">
            Compares every raw file against its Drive copy. Slow, and only necessary when a copy
            might have been altered.
          </span>
        </span>
        <ChevronDown className={`size-4 shrink-0 transition-transform ${open ? "rotate-180" : ""}`} />
      </button>
      {open ? (
        <CardContent className="space-y-4 border-t pt-4">
          <Button onClick={onRun} disabled={running}>
            {running ? "Auditing…" : hasReport ? "Run audit again" : "Run audit"}
          </Button>
          {children}
        </CardContent>
      ) : null}
    </Card>
  )
}
