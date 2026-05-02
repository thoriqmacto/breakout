"use client"

import { useCallback, useEffect, useState } from "react"
import { Loader2, Plus, RefreshCcw, Trash2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { formatIdr } from "@/lib/currency"
import {
  createCashMovement,
  deleteCashMovement,
  fetchCashMovements,
  type CashMovementKind,
  type CashMovementRecord,
} from "@/lib/portfolio-client"

type Props = {
  accessToken: string
  portfolioId: number | null
  onChange?: () => void
}

const KINDS: { value: CashMovementKind; label: string }[] = [
  { value: "deposit", label: "Deposit" },
  { value: "withdraw", label: "Withdraw" },
  { value: "dividend", label: "Dividend" },
  { value: "fee", label: "Fee" },
  { value: "adjustment", label: "Adjustment" },
]

const todayIso = () => new Date().toISOString().split("T")[0]

const signedClass = (signed: number) =>
  signed > 0 ? "text-emerald-600" : signed < 0 ? "text-rose-600" : "text-muted-foreground"

export function CashMovementsCard({ accessToken, portfolioId, onChange }: Props) {
  const [rows, setRows] = useState<CashMovementRecord[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [deletingId, setDeletingId] = useState<number | null>(null)
  const [kind, setKind] = useState<CashMovementKind>("deposit")
  const [amount, setAmount] = useState("")
  const [executedAt, setExecutedAt] = useState(todayIso())
  const [note, setNote] = useState("")

  const refresh = useCallback(async () => {
    if (!portfolioId) {
      setRows([])
      return
    }
    setLoading(true)
    setError(null)
    try {
      const data = await fetchCashMovements(accessToken, portfolioId)
      setRows(data)
    } catch (e) {
      setError(e instanceof Error ? e.message : "Unable to load cash movements.")
    } finally {
      setLoading(false)
    }
  }, [accessToken, portfolioId])

  useEffect(() => {
    void refresh()
  }, [refresh])

  if (!portfolioId) {
    return null
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setError(null)
    const parsed = Number.parseFloat(amount.replace(/,/g, ""))
    if (Number.isNaN(parsed)) {
      setError("Enter a valid amount.")
      return
    }
    if (kind !== "adjustment" && parsed < 0) {
      setError("Use kind=adjustment to record a negative correction.")
      return
    }
    setSaving(true)
    try {
      await createCashMovement(accessToken, portfolioId, {
        kind,
        amount: parsed,
        executedAt,
        note: note.trim() === "" ? undefined : note.trim(),
      })
      setAmount("")
      setNote("")
      setExecutedAt(todayIso())
      await refresh()
      onChange?.()
    } catch (e) {
      setError(e instanceof Error ? e.message : "Unable to record cash movement.")
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (id: number) => {
    if (!portfolioId) return
    setDeletingId(id)
    try {
      await deleteCashMovement(accessToken, portfolioId, id)
      await refresh()
      onChange?.()
    } catch (e) {
      setError(e instanceof Error ? e.message : "Unable to delete cash movement.")
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle>Cash movements</CardTitle>
          <CardDescription>
            Deposits, withdrawals, dividends, fees, and manual adjustments. Adjustments accept
            negative amounts; everything else must be non-negative.
          </CardDescription>
        </div>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => void refresh()}
          disabled={loading}
        >
          {loading ? (
            <Loader2 className="size-4 animate-spin" aria-hidden />
          ) : (
            <RefreshCcw className="size-4" aria-hidden />
          )}
          <span className="ml-2">Refresh</span>
        </Button>
      </CardHeader>

      <CardContent className="space-y-4">
        {error ? (
          <p className="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
            {error}
          </p>
        ) : null}

        <form onSubmit={handleSubmit} className="grid gap-3 md:grid-cols-5">
          <label className="flex flex-col gap-1 text-xs">
            <span className="text-muted-foreground">Kind</span>
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm"
              value={kind}
              onChange={(e) => setKind(e.target.value as CashMovementKind)}
            >
              {KINDS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label className="flex flex-col gap-1 text-xs">
            <span className="text-muted-foreground">Amount (IDR)</span>
            <Input
              type="number"
              inputMode="decimal"
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              placeholder="1,000,000"
              required
            />
          </label>

          <label className="flex flex-col gap-1 text-xs">
            <span className="text-muted-foreground">Executed at</span>
            <Input
              type="date"
              value={executedAt}
              onChange={(e) => setExecutedAt(e.target.value)}
              required
            />
          </label>

          <label className="flex flex-col gap-1 text-xs md:col-span-1">
            <span className="text-muted-foreground">Note</span>
            <Input
              type="text"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="optional"
            />
          </label>

          <div className="flex items-end">
            <Button type="submit" disabled={saving} className="w-full md:w-auto">
              {saving ? (
                <Loader2 className="size-4 animate-spin" aria-hidden />
              ) : (
                <Plus className="size-4" aria-hidden />
              )}
              <span className="ml-2">Add</span>
            </Button>
          </div>
        </form>

        {rows.length === 0 ? (
          <p className="rounded border border-dashed bg-muted/30 px-3 py-6 text-center text-sm text-muted-foreground">
            No cash movements yet.
          </p>
        ) : (
          <div className="overflow-x-auto rounded border">
            <table className="min-w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="px-3 py-2 text-left">Date</th>
                  <th className="px-3 py-2 text-left">Kind</th>
                  <th className="px-3 py-2 text-right">Amount</th>
                  <th className="px-3 py-2 text-right">Signed</th>
                  <th className="px-3 py-2 text-left">Note</th>
                  <th className="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-t">
                    <td className="px-3 py-2">{row.executed_at ?? "—"}</td>
                    <td className="px-3 py-2 capitalize">{row.kind}</td>
                    <td className="px-3 py-2 text-right">{formatIdr(row.amount)}</td>
                    <td className={`px-3 py-2 text-right ${signedClass(row.signed_amount)}`}>
                      {formatIdr(row.signed_amount)}
                    </td>
                    <td className="px-3 py-2 text-muted-foreground">{row.note ?? "—"}</td>
                    <td className="px-3 py-2 text-right">
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={deletingId === row.id}
                        onClick={() => void handleDelete(row.id)}
                        aria-label="Delete cash movement"
                      >
                        {deletingId === row.id ? (
                          <Loader2 className="size-4 animate-spin" aria-hidden />
                        ) : (
                          <Trash2 className="size-4" aria-hidden />
                        )}
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
