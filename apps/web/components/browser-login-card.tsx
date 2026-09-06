"use client"

import { FormEvent, useState } from "react"
import { KeyRound, ShieldAlert, TriangleAlert } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

/**
 * The status the API returns after a headless login — never the token.
 *
 * `fingerprint` is the token's last four characters, which is enough to tell
 * two tokens apart and useless to anyone who intercepts it.
 */
type TokenStatus = {
  status: string
  configured: boolean
  source: string
  fingerprint: string | null
  expires_at: string | null
  expires_in_human: string | null
  message: string
  captured_from?: string
  elapsed_ms?: number
}

type Outcome =
  | { kind: "success"; status: TokenStatus }
  | { kind: "error"; message: string; code: string | null }

function errorCodeOf(payload: unknown): string | null {
  const errors = (payload as { errors?: { code?: string[] } })?.errors

  return errors?.code?.[0] ?? null
}

/**
 * Sign in to the portal with a real browser and capture the bearer it issues.
 *
 * The credentials are held in component state for exactly as long as the
 * request takes and are cleared the moment it returns — there is no "remember
 * me" here on purpose. The server does not store them either: it uses them
 * for one attempt and keeps only the token, through the same encrypted store
 * a pasted token goes to.
 */
export function BrowserLoginCard({ onTokenStored }: { onTokenStored?: () => void }) {
  const { accessToken } = useAuth()
  const [username, setUsername] = useState("")
  const [password, setPassword] = useState("")
  const [busy, setBusy] = useState(false)
  const [outcome, setOutcome] = useState<Outcome | null>(null)

  const submit = async (event: FormEvent) => {
    event.preventDefault()

    if (busy || !username || !password) return

    if (!accessToken) {
      setOutcome({ kind: "error", message: "You are not signed in.", code: null })

      return
    }

    setBusy(true)
    setOutcome(null)

    try {
      // A bearer header, like every other call in this app -- not a cookie.
      // Asking the browser to attach credentials was wrong twice over: this
      // API authenticates with a token rather than a session, and a
      // credentialed request whose response carries no
      // Access-Control-Allow-Credentials is rejected by the browser before the
      // response is delivered -- which is what turned an ordinary request into
      // an opaque CORS failure.
      const response = await fetch(buildApiUrl("/v1/automation/stockbit-token/browser-login"), {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify({ username, password }),
      })

      const payload = await parseJson<ApiResponse<TokenStatus>>(response)

      if (!response.ok || !payload || payload.status !== "success") {
        setOutcome({
          kind: "error",
          message:
            (payload && "message" in payload && typeof payload.message === "string"
              ? payload.message
              : null) ?? "The headless login failed.",
          code: errorCodeOf(payload),
        })

        return
      }

      setOutcome({ kind: "success", status: payload.data })
      onTokenStored?.()
    } catch (reason) {
      setOutcome({
        kind: "error",
        message: reason instanceof Error ? reason.message : "The headless login failed.",
        code: null,
      })
    } finally {
      // Whatever happened, the password does not stay in memory afterwards.
      setPassword("")
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-3">
          <KeyRound className="size-6 text-primary" />
          <div>
            <CardTitle>Headless login</CardTitle>
            <CardDescription>
              Sign in through a real browser and capture the bearer token it issues, instead of
              copying one out of devtools.
            </CardDescription>
          </div>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex gap-3 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
          <ShieldAlert className="size-5 shrink-0 text-amber-600" />
          <div className="space-y-1">
            <p className="font-medium">Your password reaches the server for this request.</p>
            <p className="text-muted-foreground">
              It is used once, passed to the browser process on its standard input, and never
              written to the database, a log, or this page again. Only the resulting token is
              kept, in the same encrypted store a pasted token goes to. Automating this on a
              schedule would mean storing the password, which this deliberately does not do.
            </p>
          </div>
        </div>

        <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
          <label className="flex flex-col gap-1 text-sm">
            <span className="text-muted-foreground">Username or email</span>
            <Input
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              autoComplete="off"
              placeholder="you@example.com"
              disabled={busy}
            />
          </label>
          <label className="flex flex-col gap-1 text-sm">
            <span className="text-muted-foreground">Password</span>
            <Input
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete="off"
              disabled={busy}
            />
          </label>
          <div className="sm:col-span-2">
            <Button type="submit" disabled={busy || !username || !password}>
              {busy ? "Signing in…" : "Sign in and capture token"}
            </Button>
          </div>
        </form>

        {outcome?.kind === "success" ? (
          <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-3 text-sm">
            <p className="font-medium text-emerald-700 dark:text-emerald-400">
              Token captured and stored.
            </p>
            <dl className="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Fingerprint</dt>
                <dd className="font-mono">{outcome.status.fingerprint ?? "—"}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Expires</dt>
                <dd>{outcome.status.expires_in_human ?? "unknown"}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Seen in</dt>
                <dd>{outcome.status.captured_from ?? "—"}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Took</dt>
                <dd>
                  {typeof outcome.status.elapsed_ms === "number"
                    ? `${(outcome.status.elapsed_ms / 1000).toFixed(1)}s`
                    : "—"}
                </dd>
              </div>
            </dl>
          </div>
        ) : null}

        {outcome?.kind === "error" ? (
          <div className="flex gap-3 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm">
            <TriangleAlert className="size-5 shrink-0 text-destructive" />
            <div>
              <p className="text-destructive">{outcome.message}</p>
              {outcome.code ? (
                <p className="mt-1 font-mono text-xs text-muted-foreground">{outcome.code}</p>
              ) : null}
            </div>
          </div>
        ) : null}

        <p className="text-xs text-muted-foreground">
          Disabled unless the server sets <code className="font-mono">BROWSER_AUTH_ENABLED</code>{" "}
          and <code className="font-mono">BROWSER_AUTH_LOGIN_URL</code>; without them this returns
          503 and no browser is launched. One login runs at a time, and attempts are capped so a
          wrong password cannot lock the portal account.
        </p>
      </CardContent>
    </Card>
  )
}
