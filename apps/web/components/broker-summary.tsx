"use client"

import { useState } from "react"
import { ChevronDown, TriangleAlert } from "lucide-react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import {
  formatCompact,
  formatDate,
  formatNumber,
  formatPrice,
  formatWindow,
  type BandarDetector,
  type BrokerEntry,
  type BrokerWindow,
  type Coverage,
} from "@/lib/broker-summary-client"

/**
 * The window's range, shown as the headline. A multi-day aggregate is labelled
 * as one so it can never be read as a trading day.
 */
export function WindowBadge({ window }: { window: BrokerWindow }) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-base font-semibold">
        {formatWindow(window.from_date, window.to_date)}
      </span>
      {window.is_single_day ? (
        <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs">Single day</span>
      ) : (
        <span className="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs text-primary">
          Range aggregate
        </span>
      )}
    </div>
  )
}

/**
 * Stockbit caps each broker list at the requested limit while still reporting
 * the true totals, so a window can hold 25 of 42 buyers. Saying so is the
 * difference between a partial list and a wrong one.
 */
export function CoverageNote({ coverage }: { coverage: Coverage }) {
  const truncated = coverage.buyers_truncated || coverage.sellers_truncated

  return (
    <div className={`flex flex-wrap gap-x-4 gap-y-1 text-xs ${truncated ? "text-amber-700 dark:text-amber-400" : "text-muted-foreground"}`}>
      {truncated ? <TriangleAlert className="size-3.5" /> : null}
      <span>
        Showing {coverage.returned_buyer_count}
        {coverage.total_buyer !== null ? ` of ${coverage.total_buyer}` : ""} net buyers
      </span>
      <span>
        Showing {coverage.returned_seller_count}
        {coverage.total_seller !== null ? ` of ${coverage.total_seller}` : ""} net sellers
      </span>
      {coverage.total_buyer === null && coverage.total_seller === null ? (
        <span>Stockbit did not report the totals, so completeness is unknown.</span>
      ) : null}
    </div>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-medium tabular-nums">{value}</dd>
    </div>
  )
}

export function WindowCard({
  window,
  selected,
  onSelect,
}: {
  window: BrokerWindow
  selected: boolean
  onSelect: (window: BrokerWindow) => void
}) {
  const detector = window.bandar_detector

  return (
    <Card
      className={`cursor-pointer transition-colors ${selected ? "border-primary" : "hover:border-muted-foreground/40"}`}
      onClick={() => onSelect(window)}
    >
      <CardHeader>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="space-y-1">
            <CardTitle className="text-lg">{window.symbol ?? "—"}</CardTitle>
            <WindowBadge window={window} />
          </div>
          {detector?.broker_accdist ? (
            <span className="rounded-full bg-muted px-3 py-1 text-xs font-medium">
              {detector.broker_accdist}
            </span>
          ) : null}
        </div>
        <CardDescription>{window.transaction_type ?? "—"}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label="Buyers − sellers" value={formatNumber(detector?.number_broker_buysell ?? null)} />
          <Stat label="Total buyer" value={formatNumber(detector?.total_buyer ?? null)} />
          <Stat label="Total seller" value={formatNumber(detector?.total_seller ?? null)} />
          <Stat label="Avg price" value={formatPrice(detector?.average_price ?? null)} />
          <Stat label="Value" value={formatCompact(detector?.value ?? null)} />
          <Stat label="Volume" value={formatCompact(detector?.volume ?? null)} />
        </dl>
        <CoverageNote coverage={window.coverage} />
      </CardContent>
    </Card>
  )
}

const NUMERIC = "px-4 py-3 text-right tabular-nums whitespace-nowrap"

export function EntryRow({ entry, showWindow }: { entry: BrokerEntry; showWindow: boolean }) {
  const negative = (entry.net_value ?? 0) < 0

  return (
    <tr className="hover:bg-muted/30">
      {showWindow ? (
        <>
          <td className="whitespace-nowrap px-4 py-3">{formatDate(entry.window?.from_date ?? null)}</td>
          <td className="whitespace-nowrap px-4 py-3">{formatDate(entry.window?.to_date ?? null)}</td>
          <td className="whitespace-nowrap px-4 py-3 font-medium">{entry.window?.symbol ?? "—"}</td>
        </>
      ) : null}
      <td className="px-4 py-3">
        <span
          className={`rounded-full px-2 py-0.5 text-xs ${
            entry.side === "buy"
              ? "bg-emerald-500/10 text-emerald-700 dark:text-emerald-400"
              : "bg-rose-500/10 text-rose-700 dark:text-rose-400"
          }`}
        >
          {entry.side === "buy" ? "Net buy" : "Net sell"}
        </span>
      </td>
      <td className="whitespace-nowrap px-4 py-3 font-mono text-xs font-medium">{entry.broker_code}</td>
      <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">{entry.broker_type ?? "—"}</td>
      <td className={NUMERIC}>{formatNumber(entry.frequency)}</td>
      <td className={`${NUMERIC} ${negative ? "text-rose-700 dark:text-rose-400" : ""}`}>
        {formatNumber(entry.net_lot)}
      </td>
      <td className={`${NUMERIC} ${negative ? "text-rose-700 dark:text-rose-400" : ""}`}>
        {formatCompact(entry.net_value)}
      </td>
      <td className={NUMERIC}>{formatNumber(entry.gross_volume)}</td>
      <td className={NUMERIC}>{formatCompact(entry.gross_value)}</td>
      <td className={NUMERIC}>{formatPrice(entry.average_price)}</td>
      <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">
        {formatDate(entry.source_date)}
      </td>
    </tr>
  )
}

export function EntriesTable({
  entries,
  showWindow = true,
}: {
  entries: BrokerEntry[]
  showWindow?: boolean
}) {
  return (
    <div className="overflow-x-auto rounded-lg border">
      <table className="w-full min-w-[1100px] text-sm">
        <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
          <tr>
            {showWindow ? (
              <>
                <th className="px-4 py-3">From</th>
                <th className="px-4 py-3">To</th>
                <th className="px-4 py-3">Symbol</th>
              </>
            ) : null}
            <th className="px-4 py-3">Side</th>
            <th className="px-4 py-3">Broker</th>
            <th className="px-4 py-3">Type</th>
            <th className="px-4 py-3 text-right">Frequency</th>
            <th className="px-4 py-3 text-right">Net lot</th>
            <th className="px-4 py-3 text-right">Net value</th>
            <th className="px-4 py-3 text-right">Gross volume</th>
            <th className="px-4 py-3 text-right">Gross value</th>
            <th className="px-4 py-3 text-right">Avg price</th>
            <th className="px-4 py-3">Source date</th>
          </tr>
        </thead>
        <tbody className="divide-y">
          {entries.map((entry) => (
            <EntryRow key={entry.id} entry={entry} showWindow={showWindow} />
          ))}
        </tbody>
      </table>
      {entries.length === 0 ? (
        <p className="p-8 text-center text-muted-foreground">No broker entries match these filters.</p>
      ) : null}
    </div>
  )
}

function MetricGroup({ name, group }: { name: string; group: Record<string, unknown> }) {
  return (
    <div className="rounded-lg border p-3">
      <p className="mb-2 font-mono text-xs font-medium uppercase tracking-wide">{name}</p>
      <dl className="space-y-1 text-sm">
        {Object.entries(group).map(([key, value]) => (
          <div key={key} className="flex justify-between gap-3">
            <dt className="text-muted-foreground">{key}</dt>
            <dd className="tabular-nums">
              {typeof value === "number"
                ? formatCompact(value)
                : typeof value === "string" || typeof value === "boolean"
                  ? String(value)
                  : JSON.stringify(value)}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

/**
 * Every group is rendered, including any Stockbit adds later, so an unknown
 * metric stays inspectable rather than disappearing into a JSON blob.
 */
export function DetectorPanel({
  window,
  detector,
}: {
  window: BrokerWindow
  detector: BandarDetector
}) {
  const [open, setOpen] = useState(true)
  const groups = Object.entries(detector.metrics ?? {})

  return (
    <Card>
      <CardHeader>
        <CardTitle>Bandar detector</CardTitle>
        <CardDescription>
          For {formatWindow(window.from_date, window.to_date)} · {window.transaction_type ?? "—"}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Stat label="Broker acc/dist" value={detector.broker_accdist ?? "—"} />
          <Stat label="Buyers − sellers" value={formatNumber(detector.number_broker_buysell)} />
          <Stat label="Total buyer" value={formatNumber(detector.total_buyer)} />
          <Stat label="Total seller" value={formatNumber(detector.total_seller)} />
          <Stat label="Value" value={formatCompact(detector.value)} />
          <Stat label="Volume" value={formatCompact(detector.volume)} />
          <Stat label="Average price" value={formatPrice(detector.average_price)} />
        </dl>

        {groups.length > 0 ? (
          <div className="rounded-lg border">
            <button
              type="button"
              onClick={() => setOpen((value) => !value)}
              className="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-medium"
            >
              Metric groups ({groups.length})
              <ChevronDown className={`size-4 transition-transform ${open ? "rotate-180" : ""}`} />
            </button>
            {open ? (
              <div className="grid gap-3 border-t p-3 sm:grid-cols-2 lg:grid-cols-3">
                {groups.map(([name, group]) => (
                  <MetricGroup key={name} name={name} group={group as Record<string, unknown>} />
                ))}
              </div>
            ) : null}
          </div>
        ) : null}
      </CardContent>
    </Card>
  )
}
