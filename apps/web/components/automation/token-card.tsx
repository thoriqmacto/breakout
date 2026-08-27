"use client"

import { useState, type FormEvent } from "react"
import { KeyRound, ShieldAlert, Trash2 } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { TokenStatusBadge } from "@/components/automation/badges"
import {
  clearStockbitToken,
  formatJakarta,
  renewStockbitToken,
  type StockbitTokenState,
} from "@/lib/automation-client"

/**
 * Token management.
 *
 * Everything shown here is deliberately non-reversible into a credential: a
 * source, a four-character fingerprint, an expiry. The API has no endpoint that
 * returns the bearer, and the value typed below is sent once and then dropped
 * from component state — it is never stored in localStorage or a URL.
 */
export function StockbitTokenCard({
  token,
  status,
  onChange,
}: {
  token: string
  status: StockbitTokenState
  onChange: (next: StockbitTokenState) => void
}) {
  const [value, setValue] = useState("")
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (busy || value.trim() === "") return

    setBusy(true)
    setError(null)
    setNotice(null)

    try {
      const next = await renewStockbitToken(token, value.trim())
      // Cleared immediately: there is no reason for the bearer to outlive the
      // request that saved it.
      setValue("")
      setNotice("Stockbit token saved.")
      onChange(next)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to save the Stockbit token")
    } finally {
      setBusy(false)
    }
  }

  const clear = async () => {
    if (busy) return
    setBusy(true)
    setError(null)
    setNotice(null)

    try {
      onChange(await clearStockbitToken(token))
      setNotice("Stockbit token cleared.")
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to clear the Stockbit token")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <KeyRound className="size-4" aria-hidden /> Stockbit token
        </CardTitle>
        <CardDescription>
          Scheduled scraping stops when this expires. Stockbit tokens cannot be renewed
          automatically, so paste a fresh one here when the reminder asks.
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-xs uppercase text-muted-foreground">Status</dt>
            <dd className="mt-1">
              <TokenStatusBadge status={status.status} />
            </dd>
          </div>
          <div>
            <dt className="text-xs uppercase text-muted-foreground">Source</dt>
            <dd className="mt-1 font-medium">{status.configured ? status.source : "—"}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase text-muted-foreground">Fingerprint</dt>
            <dd className="mt-1 font-mono">{status.fingerprint ?? "—"}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase text-muted-foreground">Expires</dt>
            <dd className="mt-1">
              {status.expires_at ? (
                <>
                  {formatJakarta(status.expires_at)}{" "}
                  <span className="text-muted-foreground">WIB</span>
                  {status.expires_in_human ? (
                    <span className="text-muted-foreground"> · {status.expires_in_human} left</span>
                  ) : null}
                </>
              ) : (
                "—"
              )}
            </dd>
          </div>
        </dl>

        <p className="text-sm text-muted-foreground">{status.message}</p>

        {!status.can_start_bulk_job && status.configured ? (
          <p className="flex items-start gap-2 rounded-md bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
            <ShieldAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            Bulk Stockbit jobs need at least {status.min_ttl_minutes} minutes of remaining
            lifetime, so they stand down until this is renewed rather than fail halfway.
          </p>
        ) : null}

        <form onSubmit={submit} className="space-y-3">
          <label className="block text-sm font-medium" htmlFor="stockbit-token">
            Renew Stockbit token
          </label>
          <Input
            id="stockbit-token"
            type="password"
            autoComplete="off"
            spellCheck={false}
            placeholder="Paste the JWT (eyJ…)"
            value={value}
            onChange={(event) => setValue(event.target.value)}
            disabled={busy}
          />
          <p className="text-xs text-muted-foreground">
            The token is sent once and stored encrypted on the server. It is never returned by
            the API, never written to the run history, and never kept in this browser.
          </p>

          <div className="flex flex-wrap gap-2">
            <Button type="submit" disabled={busy || value.trim() === ""}>
              Save token
            </Button>
            {status.configured ? (
              <Button type="button" variant="outline" onClick={() => void clear()} disabled={busy}>
                <Trash2 className="size-4" aria-hidden /> Clear
              </Button>
            ) : null}
          </div>
        </form>

        {error ? <p className="text-sm text-destructive">{error}</p> : null}
        {notice ? (
          <p className="text-sm text-emerald-700 dark:text-emerald-400">{notice}</p>
        ) : null}
      </CardContent>
    </Card>
  )
}
