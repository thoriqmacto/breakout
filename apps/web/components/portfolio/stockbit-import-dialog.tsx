"use client"

import { useCallback, useMemo, useRef, useState } from "react"
import { FileUp, Loader2, TriangleAlert, Upload, X } from "lucide-react"

import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { formatIdr } from "@/lib/currency"
import {
  IMPORT_STATUS_LABELS,
  IMPORT_STATUS_TONE,
  commitStockbitImport,
  previewStockbitImport,
  type ImportAnalysis,
  type ImportRow,
  type ImportRowStatus,
  type SnapshotReconciliationRow,
} from "@/lib/portfolio-client"

/**
 * Paste a Stockbit API response, see exactly what Breakout made of it, then
 * confirm.
 *
 * The preview is the server's own analysis rendered back — this component
 * never decides what is importable, and the commit re-sends the raw payload
 * rather than the previewed rows, so what gets written is always what the
 * server just re-derived.
 */
export function StockbitImportDialog({
  accessToken,
  portfolioId,
  portfolioName,
  onClose,
  onImported,
}: {
  accessToken: string
  portfolioId: number
  portfolioName: string
  onClose: () => void
  onImported: () => void | Promise<void>
}) {
  const [payload, setPayload] = useState("")
  const [analysis, setAnalysis] = useState<ImportAnalysis | null>(null)
  const [previewing, setPreviewing] = useState(false)
  const [importing, setImporting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [createOpeningPositions, setCreateOpeningPositions] = useState(false)
  const [reconcileCash, setReconcileCash] = useState(false)
  const fileInputRef = useRef<HTMLInputElement | null>(null)

  const options = useMemo(
    () => ({ createSnapshotPositions: createOpeningPositions, reconcileCash }),
    [createOpeningPositions, reconcileCash],
  )

  // Any edit invalidates the preview: importing against a stale analysis would
  // show the user one thing and commit another.
  const updatePayload = useCallback((value: string) => {
    setPayload(value)
    setAnalysis(null)
    setNotice(null)
  }, [])

  const loadFile = useCallback(
    async (file: File) => {
      setError(null)
      try {
        updatePayload(await file.text())
      } catch {
        setError("That file could not be read.")
      }
    },
    [updatePayload],
  )

  const runPreview = useCallback(async () => {
    if (!payload.trim() || previewing) return

    setPreviewing(true)
    setError(null)
    setNotice(null)

    try {
      setAnalysis(await previewStockbitImport(accessToken, portfolioId, payload, options))
    } catch (cause) {
      setAnalysis(null)
      setError(cause instanceof Error ? cause.message : "Unable to preview this import.")
    } finally {
      setPreviewing(false)
    }
  }, [accessToken, options, payload, portfolioId, previewing])

  const runImport = useCallback(async () => {
    if (!analysis?.can_commit || importing) return

    setImporting(true)
    setError(null)

    try {
      const { result, message } = await commitStockbitImport(
        accessToken,
        portfolioId,
        payload,
        options,
      )
      setAnalysis(result)
      setNotice(message)
      await onImported()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to import this payload.")
    } finally {
      setImporting(false)
    }
  }, [accessToken, analysis, importing, onImported, options, payload, portfolioId])

  const rows = useMemo<ImportRow[]>(() => {
    if (!analysis) return []
    return [...analysis.trades, ...analysis.dividends, ...analysis.skipped, ...analysis.errors].sort(
      (a, b) => (a.executed_at ?? "").localeCompare(b.executed_at ?? ""),
    )
  }, [analysis])

  const blocked = analysis !== null && !analysis.can_commit

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:p-8"
      role="dialog"
      aria-modal="true"
      aria-label="Import Stockbit JSON"
    >
      <div className="w-full max-w-5xl space-y-5 rounded-xl border bg-background p-6 shadow-lg">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h2 className="text-xl font-semibold">Import Stockbit JSON</h2>
            <p className="mt-1 text-sm text-muted-foreground">
              Paste a transaction history or a portfolio snapshot for{" "}
              <span className="font-medium">{portfolioName}</span>. Nothing is written until you
              confirm.
            </p>
          </div>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close">
            <X className="size-4" />
          </Button>
        </div>

        <div className="space-y-2">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <label className="text-sm font-medium" htmlFor="stockbit-payload">
              Stockbit API response
            </label>
            <div className="flex items-center gap-2">
              <input
                ref={fileInputRef}
                type="file"
                accept="application/json,.json"
                className="hidden"
                onChange={(event) => {
                  const file = event.target.files?.[0]
                  if (file) void loadFile(file)
                  event.target.value = ""
                }}
              />
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => fileInputRef.current?.click()}
              >
                <FileUp className="size-4" aria-hidden /> Load .json
              </Button>
            </div>
          </div>
          <textarea
            id="stockbit-payload"
            className="h-56 w-full rounded-md border bg-transparent p-3 font-mono text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
            placeholder='{"message":"History Info retrieved","data":{"history":[...]}}'
            spellCheck={false}
            value={payload}
            onChange={(event) => updatePayload(event.target.value)}
          />
        </div>

        {analysis?.type === "snapshot" ? (
          <div className="space-y-2 rounded-lg border p-4">
            <p className="text-sm font-medium">Snapshot options</p>
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-1 size-4"
                checked={createOpeningPositions}
                onChange={(event) => {
                  setCreateOpeningPositions(event.target.checked)
                  setAnalysis(null)
                }}
              />
              <span>
                Create opening snapshot positions
                <span className="block text-xs text-muted-foreground">
                  Only for holdings no transaction history explains. Marked synthetic so they are
                  never confused with a real BUY. Re-preview after changing this.
                </span>
              </span>
            </label>
            <label className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                className="mt-1 size-4"
                checked={reconcileCash}
                onChange={(event) => {
                  setReconcileCash(event.target.checked)
                  setAnalysis(null)
                }}
              />
              <span>
                Reconcile cash to the broker balance
                <span className="block text-xs text-muted-foreground">
                  Adjusts the base cash so the calculated total matches the broker, without
                  double-counting imported dividends.
                </span>
              </span>
            </label>
          </div>
        ) : null}

        {error ? (
          <p className="flex items-start gap-2 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            {error}
          </p>
        ) : null}

        {notice ? (
          <p className="rounded-md bg-emerald-500/10 p-3 text-sm text-emerald-700 dark:text-emerald-400">
            {notice}
          </p>
        ) : null}

        {analysis ? <ImportPreview analysis={analysis} rows={rows} /> : null}

        <div className="flex flex-wrap items-center justify-end gap-2 border-t pt-4">
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant="outline"
            onClick={() => void runPreview()}
            disabled={previewing || !payload.trim()}
          >
            {previewing ? <Loader2 className="size-4 animate-spin" aria-hidden /> : null}
            Preview import
          </Button>
          <Button
            onClick={() => void runImport()}
            disabled={!analysis || blocked || importing || previewing}
            title={blocked ? "Resolve the blocking errors above first." : undefined}
          >
            {importing ? (
              <Loader2 className="size-4 animate-spin" aria-hidden />
            ) : (
              <Upload className="size-4" aria-hidden />
            )}
            Import
          </Button>
        </div>
      </div>
    </div>
  )
}

function StatusBadge({ status }: { status: ImportRowStatus }) {
  return (
    <span
      className={cn(
        "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold",
        IMPORT_STATUS_TONE[status],
      )}
    >
      {IMPORT_STATUS_LABELS[status]}
    </span>
  )
}

function ImportPreview({ analysis, rows }: { analysis: ImportAnalysis; rows: ImportRow[] }) {
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2 text-xs">
        <span className="rounded-full bg-muted px-3 py-1 font-medium uppercase">
          {analysis.type === "history" ? "Transaction history" : "Portfolio snapshot"}
        </span>
        <span className="rounded-full bg-emerald-500/10 px-3 py-1 text-emerald-700 dark:text-emerald-400">
          {analysis.totals.new} new
        </span>
        <span className="rounded-full bg-sky-500/10 px-3 py-1 text-sky-700 dark:text-sky-400">
          {analysis.totals.skipped_duplicate} duplicate
        </span>
        <span className="rounded-full bg-amber-500/10 px-3 py-1 text-amber-700 dark:text-amber-400">
          {analysis.totals.skipped} skipped
        </span>
        {analysis.totals.error > 0 ? (
          <span className="rounded-full bg-destructive/10 px-3 py-1 text-destructive">
            {analysis.totals.error} error
          </span>
        ) : null}
      </div>

      {analysis.missing_assets.length > 0 ? (
        <div className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
          <p className="font-medium">
            Missing asset{analysis.missing_assets.length > 1 ? "s" : ""}:{" "}
            {analysis.missing_assets.join(", ")}
          </p>
          <p className="mt-1 text-xs">
            Create or sync {analysis.missing_assets.length > 1 ? "these assets" : "this asset"},
            then preview again. Nothing is attached to a guessed instrument.
          </p>
        </div>
      ) : null}

      {analysis.warnings.length > 0 ? (
        <ul className="space-y-1 rounded-md bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300">
          {analysis.warnings.map((warning) => (
            <li key={warning}>{warning}</li>
          ))}
        </ul>
      ) : null}

      {analysis.type === "snapshot" && analysis.snapshot ? (
        <SnapshotPreview analysis={analysis} />
      ) : (
        <TradePreview rows={rows} />
      )}
    </div>
  )
}

function TradePreview({ rows }: { rows: ImportRow[] }) {
  if (rows.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">No transactions were found in that payload.</p>
    )
  }

  return (
    <div className="max-h-72 overflow-auto rounded-lg border">
      <table className="w-full min-w-[52rem] border-collapse text-xs">
        <thead className="sticky top-0 bg-muted/80 backdrop-blur">
          <tr className="text-left uppercase text-muted-foreground">
            <th className="px-3 py-2 font-medium">Date/time</th>
            <th className="px-3 py-2 font-medium">Symbol</th>
            <th className="px-3 py-2 font-medium">Command</th>
            <th className="px-3 py-2 text-right font-medium">Shares</th>
            <th className="px-3 py-2 text-right font-medium">Price</th>
            <th className="px-3 py-2 text-right font-medium">Fee</th>
            <th className="px-3 py-2 text-right font-medium">Net amount</th>
            <th className="px-3 py-2 font-medium">Status</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={`${row.external_id ?? "row"}-${index}`} className="border-t align-top">
              <td className="whitespace-nowrap px-3 py-2 font-mono">{row.executed_at ?? "—"}</td>
              <td className="px-3 py-2 font-medium">{row.symbol || "—"}</td>
              <td className="px-3 py-2">{row.command || "—"}</td>
              <td className="px-3 py-2 text-right font-mono">
                {row.shares === null ? "—" : row.shares.toLocaleString("en-US")}
              </td>
              <td className="px-3 py-2 text-right font-mono">
                {row.price === null ? "—" : row.price.toLocaleString("en-US")}
              </td>
              <td className="px-3 py-2 text-right font-mono">
                {row.fee === null ? "—" : row.fee.toLocaleString("en-US")}
              </td>
              <td className="px-3 py-2 text-right font-mono">
                {row.net_amount === null ? "—" : row.net_amount.toLocaleString("en-US")}
              </td>
              <td className="px-3 py-2">
                <StatusBadge status={row.import_status} />
                {row.reason ? (
                  <span className="mt-1 block text-[11px] text-muted-foreground">{row.reason}</span>
                ) : null}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function SnapshotPreview({ analysis }: { analysis: ImportAnalysis }) {
  const snapshot = analysis.snapshot
  if (!snapshot) return null

  return (
    <div className="space-y-4">
      <div className="max-h-72 overflow-auto rounded-lg border">
        <table className="w-full min-w-[60rem] border-collapse text-xs">
          <thead className="sticky top-0 bg-muted/80 backdrop-blur">
            <tr className="text-left uppercase text-muted-foreground">
              <th className="px-3 py-2 font-medium">Symbol</th>
              <th className="px-3 py-2 text-right font-medium">Broker shares</th>
              <th className="px-3 py-2 text-right font-medium">Breakout shares</th>
              <th className="px-3 py-2 text-right font-medium">Broker avg cost</th>
              <th className="px-3 py-2 text-right font-medium">Breakout avg cost</th>
              <th className="px-3 py-2 text-right font-medium">Broker invested</th>
              <th className="px-3 py-2 text-right font-medium">Breakout cost basis</th>
              <th className="px-3 py-2 text-right font-medium">Broker value</th>
              <th className="px-3 py-2 text-right font-medium">Breakout value</th>
              <th className="px-3 py-2 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {snapshot.positions.map((row) => (
              <SnapshotRow key={row.symbol} row={row} />
            ))}
          </tbody>
        </table>
      </div>

      {snapshot.cash ? (
        <div className="rounded-lg border p-4 text-sm">
          <p className="font-medium">Cash reconciliation</p>
          {snapshot.cash.already_reconciled ? (
            <p className="mt-1 text-muted-foreground">
              Breakout already calculates {formatIdr(snapshot.cash.current_calculated_cash)}, which
              matches the broker. No adjustment needed.
            </p>
          ) : (
            <dl className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
              <Figure label="Broker cash" value={snapshot.cash.broker_cash} />
              <Figure label="Breakout cash now" value={snapshot.cash.current_calculated_cash} />
              <Figure label="Cash movements" value={snapshot.cash.cash_movements_total} />
              <Figure label="Proposed base cash" value={snapshot.cash.proposed_base_cash} />
            </dl>
          )}
          <p className="mt-2 text-xs text-muted-foreground">
            The base is set to the broker balance minus what the cash movements already contribute,
            so imported dividends are not counted twice. Applied only if you tick the option above.
          </p>
        </div>
      ) : null}
    </div>
  )
}

function SnapshotRow({ row }: { row: SnapshotReconciliationRow }) {
  const cell = (value: number | null, matches: boolean | null, key: string) => (
    <td
      key={key}
      className={cn(
        "px-3 py-2 text-right font-mono",
        matches === false ? "text-destructive" : undefined,
      )}
    >
      {value === null ? "—" : value.toLocaleString("en-US", { maximumFractionDigits: 4 })}
    </td>
  )

  return (
    <tr className="border-t align-top">
      <td className="px-3 py-2 font-medium">{row.symbol}</td>
      {cell(row.broker_shares, row.shares_match, "bs")}
      {cell(row.breakout_shares, row.shares_match, "ks")}
      {cell(row.broker_average_price, row.average_match, "ba")}
      {cell(row.breakout_average_cost, row.average_match, "ka")}
      {cell(row.broker_amount_invested, row.invested_match, "bi")}
      {cell(row.breakout_cost_basis, row.invested_match, "ki")}
      {cell(row.broker_market_value, row.market_value_match, "bv")}
      {cell(row.breakout_market_value, row.market_value_match, "kv")}
      <td className="px-3 py-2">
        <StatusBadge status={row.import_status} />
        {row.reason ? (
          <span className="mt-1 block text-[11px] text-muted-foreground">{row.reason}</span>
        ) : null}
      </td>
    </tr>
  )
}

function Figure({ label, value }: { label: string; value: number }) {
  return (
    <div>
      <dt className="text-xs uppercase text-muted-foreground">{label}</dt>
      <dd className="font-mono">{formatIdr(value)}</dd>
    </div>
  )
}
