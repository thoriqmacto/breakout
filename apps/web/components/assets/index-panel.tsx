"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { ArrowUpRight, Check, Loader2, Plus } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import {
  fetchIndex,
  fetchIndexes,
  parsePastedSymbols,
  replaceIndexMembers,
  trackIndexMembers,
  type IndexDetail,
  type IndexMember,
  type IndexSummary,
} from "@/lib/index-client"

/**
 * A published index, and the gap between it and what this dashboard collects.
 *
 * The list itself is the least interesting part -- the exchange publishes it.
 * What this page is for is the three things the exchange's page cannot tell
 * you: which members are already being collected, which are not, and what has
 * joined or left since you last looked.
 *
 * So untracked members come first. They are the only rows with an action on
 * them, and burying them under fifty rows of "already tracked" would make the
 * one useful column the one you have to hunt for.
 */

type Filter = "untracked" | "tracked" | "all"

export function IndexPanel({ code }: { code?: string }) {
  const { accessToken } = useAuth()

  const [summaries, setSummaries] = useState<IndexSummary[]>([])
  const [detail, setDetail] = useState<IndexDetail | null>(null)
  const [active, setActive] = useState<string | null>(code ?? null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const [filter, setFilter] = useState<Filter>("untracked")
  const [search, setSearch] = useState("")
  const [selected, setSelected] = useState<string[]>([])
  const [adding, setAdding] = useState(false)

  const loadSummaries = useCallback(async () => {
    if (!accessToken) return

    try {
      const payload = await fetchIndexes(accessToken)
      setSummaries(payload.indexes)
      setActive((current) => current ?? code ?? payload.default ?? payload.indexes[0]?.code ?? null)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to load the index list.")
    }
  }, [accessToken, code])

  const loadDetail = useCallback(async () => {
    if (!accessToken || !active) return

    setLoading(true)

    try {
      setDetail(await fetchIndex(accessToken, active))
      setError(null)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to load that index.")
      setDetail(null)
    } finally {
      setLoading(false)
    }
  }, [accessToken, active])

  useEffect(() => {
    void loadSummaries()
  }, [loadSummaries])

  useEffect(() => {
    void loadDetail()
  }, [loadDetail])

  // Memoised because the fallback creates a fresh array each render, which
  // would make every downstream useMemo recompute on every keystroke.
  const members = useMemo(() => detail?.members ?? [], [detail])

  const untracked = useMemo(() => members.filter((member) => !member.tracked), [members])

  const visible = useMemo(() => {
    const term = search.trim().toUpperCase()

    return members
      .filter((member) => {
        if (filter === "untracked" && member.tracked) return false
        if (filter === "tracked" && !member.tracked) return false

        if (term === "") return true

        return (
          member.symbol.includes(term) || (member.name ?? "").toUpperCase().includes(term)
        )
      })
      .sort((a, b) => a.symbol.localeCompare(b.symbol))
  }, [members, filter, search])

  const toggle = (symbol: string) => {
    setSelected((current) =>
      current.includes(symbol)
        ? current.filter((value) => value !== symbol)
        : [...current, symbol],
    )
  }

  const addSelected = async () => {
    if (!accessToken || !active || selected.length === 0) return

    setAdding(true)
    setNotice(null)

    try {
      const outcome = await trackIndexMembers(accessToken, active, selected)

      const parts: string[] = []

      if (outcome.created.length > 0) {
        parts.push(
          `${outcome.created.length} symbol${outcome.created.length === 1 ? "" : "s"} added — ` +
            "a full history backfill from each IPO date is queued, and daily collection starts at " +
            "the next scheduled run.",
        )
      }

      if (outcome.already_tracked.length > 0) {
        parts.push(`${outcome.already_tracked.join(", ")} already tracked; collection switched on.`)
      }

      if (outcome.refused.length > 0) {
        parts.push(`${outcome.refused.join(", ")} is not a current member and was not added.`)
      }

      setNotice(parts.join(" "))
      setSelected([])
      await Promise.all([loadDetail(), loadSummaries()])
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Unable to add those symbols.")
    } finally {
      setAdding(false)
    }
  }

  const summary = summaries.find((entry) => entry.code === active) ?? detail?.index ?? null

  return (
    <div className="space-y-4">
      {summaries.length > 1 ? (
        <div className="flex flex-wrap gap-2">
          {summaries.map((entry) => (
            <Button
              key={entry.code}
              size="sm"
              variant={entry.code === active ? "default" : "outline"}
              onClick={() => {
                setActive(entry.code)
                setSelected([])
              }}
            >
              {entry.code}
            </Button>
          ))}
        </div>
      ) : null}

      {summary ? <IndexHeader summary={summary} untracked={untracked.length} /> : null}

      {error ? (
        <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </p>
      ) : null}

      {notice ? (
        <p className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          {notice}
        </p>
      ) : null}

      {detail && detail.departed.length > 0 ? (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">
              Left the index in the last {detail.index.recent_days ?? 90} days
            </CardTitle>
            <CardDescription>
              These keep their data and their place in the tables — leaving an index is not a reason
              to stop collecting a stock. The badge is gone, which is the only thing that changed.
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {detail.departed.map((member) => (
              <span
                key={member.symbol}
                className="rounded border border-border bg-muted/40 px-2 py-1 text-xs"
              >
                <span className="font-medium">{member.symbol}</span>
                <span className="text-muted-foreground"> · left {member.removed_on}</span>
              </span>
            ))}
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Constituents</CardTitle>
          <CardDescription>
            Tick the ones you want collected. Adding a symbol switches on daily OHLCV and broker
            summary, and queues a backfill that walks its whole price history from the IPO date —
            the same history the assets already here hold. The backfill runs in the background and
            waits for the evening collectors if they are working.
          </CardDescription>
        </CardHeader>

        <CardContent className="space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <FilterTab
              label={`Not tracked (${untracked.length})`}
              active={filter === "untracked"}
              onSelect={() => setFilter("untracked")}
            />
            <FilterTab
              label={`Tracked (${members.length - untracked.length})`}
              active={filter === "tracked"}
              onSelect={() => setFilter("tracked")}
            />
            <FilterTab
              label={`All (${members.length})`}
              active={filter === "all"}
              onSelect={() => setFilter("all")}
            />

            <Input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Filter by symbol or name"
              className="h-8 w-56"
            />

            {untracked.length > 0 ? (
              <Button
                size="sm"
                variant="outline"
                onClick={() =>
                  setSelected(
                    selected.length === untracked.length
                      ? []
                      : untracked.map((member) => member.symbol),
                  )
                }
              >
                {selected.length === untracked.length && untracked.length > 0
                  ? "Clear selection"
                  : `Select all ${untracked.length} untracked`}
              </Button>
            ) : null}

            <Button size="sm" disabled={selected.length === 0 || adding} onClick={() => void addSelected()}>
              {adding ? (
                <Loader2 className="mr-1 h-3 w-3 animate-spin" />
              ) : (
                <Plus className="mr-1 h-3 w-3" />
              )}
              Add {selected.length > 0 ? selected.length : ""} to breakout
            </Button>
          </div>

          {loading ? (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" /> Loading constituents…
            </p>
          ) : visible.length === 0 ? (
            <EmptyState filter={filter} hasMembers={members.length > 0} />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-left text-xs uppercase text-muted-foreground">
                  <tr>
                    <th className="w-8 py-2" />
                    <th className="py-2 pr-3">Symbol</th>
                    <th className="py-2 pr-3">Name</th>
                    <th className="py-2 pr-3">In index since</th>
                    <th className="py-2 pr-3">Collected</th>
                    <th className="py-2 pr-3 text-right">Bars</th>
                    <th className="py-2 pr-3">Last bar</th>
                  </tr>
                </thead>
                <tbody>
                  {visible.map((member) => (
                    <MemberRow
                      key={member.symbol}
                      member={member}
                      checked={selected.includes(member.symbol)}
                      onToggle={() => toggle(member.symbol)}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {active ? <PasteMembership code={active} onApplied={() => void loadDetail()} /> : null}
    </div>
  )
}

function IndexHeader({ summary, untracked }: { summary: IndexSummary; untracked: number }) {
  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">
          {summary.code} · {summary.name}
        </CardTitle>
        <CardDescription>{summary.description}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label="Members" value={String(summary.member_count)} />
          <Stat label="Collected" value={`${summary.tracked_count} of ${summary.member_count}`} />
          <Stat label="Not collected" value={String(untracked)} />
          <Stat
            label="Membership read"
            value={summary.last_effective_on ?? "never"}
            hint={summary.last_source ? `via ${summary.last_source}` : undefined}
          />
        </div>

        {summary.last_effective_on === null ? (
          <p className="rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
            <span className="font-medium">No membership has been read yet.</span>{" "}
            The nightly <code className="rounded bg-muted px-1 py-0.5">Index Membership Sync</code>{" "}
            task fills this in at 07:30 WIB, or paste the list below to fill it in now.
          </p>
        ) : null}

        {summary.source_url ? (
          <p className="text-xs text-muted-foreground">
            Source:{" "}
            <a
              href={summary.source_url}
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center gap-0.5 underline"
            >
              {summary.source_url}
              <ArrowUpRight className="h-3 w-3" />
            </a>
          </p>
        ) : null}
      </CardContent>
    </Card>
  )
}

function MemberRow({
  member,
  checked,
  onToggle,
}: {
  member: IndexMember
  checked: boolean
  onToggle: () => void
}) {
  return (
    <tr className="border-t">
      <td className="py-2">
        {member.tracked ? (
          <Check className="h-3.5 w-3.5 text-emerald-600" aria-label="Already collected" />
        ) : (
          <input
            type="checkbox"
            checked={checked}
            onChange={onToggle}
            aria-label={`Add ${member.symbol} to breakout`}
            className="h-3.5 w-3.5 cursor-pointer"
          />
        )}
      </td>
      <td className="py-2 pr-3 font-medium">
        {member.tracked && member.asset_id !== null ? (
          <Link href={`/dashboard/assets/${member.asset_id}`} className="underline">
            {member.symbol}
          </Link>
        ) : (
          member.symbol
        )}
      </td>
      <td className="py-2 pr-3 text-muted-foreground">{member.name ?? "—"}</td>
      <td className="py-2 pr-3 tabular-nums text-muted-foreground">{member.joined_on ?? "—"}</td>
      <td className="py-2 pr-3">
        {member.tracked ? (
          <CollectionState member={member} />
        ) : (
          <span className="text-muted-foreground">not collected</span>
        )}
      </td>
      <td className="py-2 pr-3 text-right tabular-nums">{member.bars ?? "—"}</td>
      <td className="py-2 pr-3 tabular-nums text-muted-foreground">{member.last_bar_date ?? "—"}</td>
    </tr>
  )
}

/**
 * Tracked is not the same as collected.
 *
 * An asset row can exist with its sync flags off, which looks identical to a
 * collected symbol everywhere that only counts rows -- and then quietly has no
 * bars. The two flags are named separately because the daily jobs read them
 * separately.
 */
function CollectionState({ member }: { member: IndexMember }) {
  const price = member.sync_price ?? false
  const broker = member.sync_broker_summary ?? false

  if (price && broker) return <span className="text-emerald-700 dark:text-emerald-400">OHLCV + broker</span>

  if (price) return <span className="text-amber-700 dark:text-amber-400">OHLCV only</span>

  if (broker) return <span className="text-amber-700 dark:text-amber-400">broker only</span>

  return <span className="text-muted-foreground">switched off</span>
}

function EmptyState({ filter, hasMembers }: { filter: Filter; hasMembers: boolean }) {
  if (!hasMembers) {
    return (
      <p className="text-sm text-muted-foreground">
        No membership stored for this index yet. Paste the list below, or wait for the nightly sync.
      </p>
    )
  }

  if (filter === "untracked") {
    return (
      <p className="text-sm text-emerald-700 dark:text-emerald-400">
        Every constituent is already being collected.
      </p>
    )
  }

  return <p className="text-sm text-muted-foreground">Nothing matches that filter.</p>
}

/**
 * The way in when the reader cannot read.
 *
 * The catalogue page belongs to somebody else and its markup will change. When
 * it does, the scheduled read fails loudly and this is how the membership
 * still gets updated -- paste the symbols from the page, in any shape, and the
 * server applies the same guards it applies to a scraped list.
 */
function PasteMembership({ code, onApplied }: { code: string; onApplied: () => void }) {
  const { accessToken } = useAuth()

  const [open, setOpen] = useState(false)
  const [raw, setRaw] = useState("")
  const [force, setForce] = useState(false)
  const [saving, setSaving] = useState(false)
  const [result, setResult] = useState<string | null>(null)
  const [failure, setFailure] = useState<string | null>(null)

  const parsed = useMemo(() => parsePastedSymbols(raw), [raw])

  const submit = async () => {
    if (!accessToken || parsed.length === 0) return

    setSaving(true)
    setResult(null)
    setFailure(null)

    try {
      const outcome = await replaceIndexMembers(accessToken, code, parsed, force)

      setResult(
        `${outcome.member_count} members stored: ${outcome.joined.length} joined, ` +
          `${outcome.removed.length} left.`,
      )
      setRaw("")
      setForce(false)
      onApplied()
    } catch (cause) {
      setFailure(cause instanceof Error ? cause.message : "Unable to update the membership.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Update the membership by hand</CardTitle>
        <CardDescription>
          For when the nightly read fails — the catalogue page is not ours and its markup changes.
          Paste the constituent list in any shape; anything ticker-shaped is picked out and the same
          safety checks apply.
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-3">
        {open ? (
          <>
            <textarea
              value={raw}
              onChange={(event) => setRaw(event.target.value)}
              rows={6}
              placeholder="BBCA BBRI TLKM … or a whole table copied from the page"
              className="w-full rounded-md border border-input bg-transparent p-2 font-mono text-xs shadow-xs"
            />

            <div className="flex flex-wrap items-center gap-3 text-sm">
              <span className="text-muted-foreground">
                {parsed.length} ticker-shaped {parsed.length === 1 ? "entry" : "entries"} found
              </span>

              <label className="flex items-center gap-1.5 text-xs">
                <input
                  type="checkbox"
                  checked={force}
                  onChange={(event) => setForce(event.target.checked)}
                  className="h-3.5 w-3.5"
                />
                Override the safety checks (the index really was rebuilt)
              </label>
            </div>

            <div className="flex items-center gap-2">
              <Button size="sm" disabled={parsed.length === 0 || saving} onClick={() => void submit()}>
                {saving ? <Loader2 className="mr-1 h-3 w-3 animate-spin" /> : null}
                Replace membership
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setOpen(false)}>
                Cancel
              </Button>
            </div>
          </>
        ) : (
          <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
            Paste a list
          </Button>
        )}

        {result ? (
          <p className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            {result}
          </p>
        ) : null}

        {failure ? (
          <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {failure}
          </p>
        ) : null}
      </CardContent>
    </Card>
  )
}

function FilterTab({
  label,
  active,
  onSelect,
}: {
  label: string
  active: boolean
  onSelect: () => void
}) {
  return (
    <button
      type="button"
      onClick={onSelect}
      aria-pressed={active}
      className={`rounded-md border px-2.5 py-1 text-xs font-medium transition-colors ${
        active
          ? "border-primary bg-primary/10 text-foreground"
          : "border-transparent text-muted-foreground hover:text-foreground"
      }`}
    >
      {label}
    </button>
  )
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="rounded-md border bg-muted/30 p-2">
      <p className="text-xs uppercase text-muted-foreground">{label}</p>
      <p className="mt-0.5 font-medium tabular-nums">{value}</p>
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  )
}
