"use client"

import { useCallback, useEffect, useState } from "react"
import { RefreshCw, TriangleAlert } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import {
  CollectionCard,
  DriveHealthCard,
  LocalLocationCard,
  PushOutcome,
  StateGuidance,
  confirmOverwrite,
  formatTime,
} from "@/components/backup-status"
import {
  AssetReconciliationDetail,
  DeepAuditPanel,
  FlowSnapshotCard,
  ReadinessBanner,
  ReadinessCards,
  ReconciliationTable,
} from "@/components/reconciliation-status"
import {
  fetchAudit,
  fetchReadiness,
  pushToDrive,
  type PushResult,
  type ReadinessReport,
  type Report,
} from "@/lib/backup-client"
import {
  fetchReconciliation,
  fetchReconciliationAsset,
  type ReconciliationDetail,
  type ReconciliationList,
  type ReconciliationQuery,
} from "@/lib/reconciliation-client"

export default function BackupStatusPage() {
  const { accessToken } = useAuth()

  const [readiness, setReadiness] = useState<ReadinessReport | null>(null)
  const [list, setList] = useState<ReconciliationList | null>(null)
  const [query, setQuery] = useState<ReconciliationQuery>({ page: 1, per_page: 25, sort: "symbol" })
  const [detail, setDetail] = useState<ReconciliationDetail | null>(null)
  const [selected, setSelected] = useState<string | null>(null)

  const [audit, setAudit] = useState<Report | null>(null)
  const [auditing, setAuditing] = useState(false)

  const [loading, setLoading] = useState(true)
  const [listLoading, setListLoading] = useState(false)
  const [pushing, setPushing] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [outcome, setOutcome] = useState<PushResult | null>(null)

  const loadReadiness = useCallback(async () => {
    if (!accessToken) return
    setLoading(true)
    setError(null)
    try {
      setReadiness(await fetchReadiness(accessToken))
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to load backup readiness")
    } finally {
      setLoading(false)
    }
  }, [accessToken])

  useEffect(() => {
    void loadReadiness()
  }, [loadReadiness])

  // The list is a manifest read, so re-querying on every filter change costs
  // one file read regardless of how many symbols the universe carries.
  useEffect(() => {
    if (!accessToken) return

    let cancelled = false
    setListLoading(true)

    fetchReconciliation(accessToken, query)
      .then((result) => {
        if (!cancelled) setList(result)
      })
      .catch((reason: unknown) => {
        if (!cancelled) {
          setError(
            reason instanceof Error ? reason.message : "Unable to load the reconciliation index",
          )
        }
      })
      .finally(() => {
        if (!cancelled) setListLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [accessToken, query])

  // A document is opened only when one asset is expanded.
  useEffect(() => {
    if (!accessToken || selected === null) {
      setDetail(null)

      return
    }

    let cancelled = false

    fetchReconciliationAsset(accessToken, selected)
      .then((result) => {
        if (!cancelled) setDetail(result)
      })
      .catch((reason: unknown) => {
        if (!cancelled) {
          setError(
            reason instanceof Error
              ? reason.message
              : `Unable to load the reconciliation document for ${selected}`,
          )
        }
      })

    return () => {
      cancelled = true
    }
  }, [accessToken, selected])

  const runAudit = useCallback(
    async (fresh = false) => {
      if (!accessToken || auditing) return
      setAuditing(true)
      setError(null)
      try {
        const report = await fetchAudit(accessToken, fresh)
        setAudit(report)
        setReadiness(report.readiness_report)
      } catch (reason) {
        setError(reason instanceof Error ? reason.message : "Unable to run the deep audit")
      } finally {
        setAuditing(false)
      }
    },
    [accessToken, auditing],
  )

  // A push returns a freshly computed report, so the audit table settles
  // without a second round trip and can never show a just-pushed file as
  // still pending.
  const push = useCallback(
    async (symbols?: string[], collection: "historical" | "broker_summary" = "historical") => {
      if (!accessToken || pushing) return
      setPushing(true)
      setError(null)
      setOutcome(null)
      try {
        const result = await pushToDrive(accessToken, symbols, collection)
        setOutcome(result)
        setAudit(result.report)
        await loadReadiness()
      } catch (reason) {
        setError(reason instanceof Error ? reason.message : "The mirror push failed")
      } finally {
        setPushing(false)
      }
    },
    [accessToken, pushing, loadReadiness],
  )

  const historical = audit?.collections.find((collection) => collection.key === "historical")
  const pending = historical?.counts.pending_push ?? 0

  return (
    <div className="space-y-6 p-6 lg:p-8">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-primary">Data resilience</p>
          <h1 className="text-3xl font-semibold tracking-tight">Backups &amp; recovery</h1>
          <p className="mt-2 max-w-3xl text-muted-foreground">
            Three layers: the raw broker-summary files as they arrived, a per-asset reconciliation
            document that can rebuild the database, and a mirror of both in cold storage. This page
            opens on whether a recovery would succeed; the file-by-file comparison is available
            below when you need it.
          </p>
        </div>
        <Button
          variant="outline"
          onClick={() => void loadReadiness()}
          disabled={loading || pushing}
        >
          <RefreshCw className={loading ? "animate-spin" : ""} /> Refresh
        </Button>
      </div>

      {error ? (
        <Card className="border-destructive/40">
          <CardContent className="flex items-center gap-3 py-6 text-destructive">
            <TriangleAlert className="size-5" />
            {error}
          </CardContent>
        </Card>
      ) : null}

      {loading && !readiness ? (
        <div className="grid gap-4 md:grid-cols-2">
          {[0, 1].map((index) => (
            <Card key={index} className="h-40 animate-pulse bg-muted/40" />
          ))}
        </div>
      ) : null}

      {readiness ? (
        <>
          <ReadinessBanner report={readiness} />

          <ReadinessCards
            report={readiness}
            pushing={pushing}
            onPushMirror={() => void push(undefined, "broker_summary")}
          />

          {outcome ? <PushOutcome result={outcome} /> : null}

          <FlowSnapshotCard snapshot={readiness.flow_snapshot} />
        </>
      ) : null}

      {list ? (
        <ReconciliationTable
          list={list}
          query={query}
          loading={listLoading}
          selected={selected}
          onQueryChange={setQuery}
          onSelect={setSelected}
        />
      ) : null}

      {detail ? (
        <AssetReconciliationDetail detail={detail} onClose={() => setSelected(null)} />
      ) : null}

      <DeepAuditPanel running={auditing} hasReport={audit !== null} onRun={() => void runAudit(true)}>
        {audit ? (
          <div className="space-y-4">
            {pending > 0 ? (
              <Card className="border-amber-500/50">
                <CardContent className="flex flex-col gap-4 py-5 sm:flex-row sm:items-center sm:justify-between">
                  <p className="font-medium">
                    {pending} historical backup {pending === 1 ? "file is" : "files are"} not
                    synchronized with Google Drive.
                  </p>
                  <Button
                    onClick={() => {
                      const ambiguous = (historical?.files ?? [])
                        .filter((file) => file.can_push && file.state === "different")
                        .map((file) => file.name)

                      if (ambiguous.length > 0 && !confirmOverwrite(ambiguous)) return
                      void push()
                    }}
                    disabled={pushing}
                  >
                    {pushing ? "Pushing…" : "Push pending to Drive"}
                  </Button>
                </CardContent>
              </Card>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-2">
              <LocalLocationCard
                available={audit.locations.find((l) => l.key === "local")?.available ?? true}
              />
              <DriveHealthCard health={audit.google_drive} />
            </div>

            {audit.collections.map((collection) => (
              <CollectionCard
                key={collection.key}
                collection={collection}
                pushing={pushing}
                onPush={(symbol) => void push([symbol])}
              />
            ))}

            <StateGuidance />

            <p className="text-right text-xs text-muted-foreground">
              Audited {formatTime(audit.generated_at)}
            </p>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">
            The audit walks both collections and compares content, which is the only way to catch a
            Drive copy that was silently truncated or edited. It is an explicit action because its
            cost grows with every archived file.
          </p>
        )}
      </DeepAuditPanel>

      {readiness ? (
        <p className="text-right text-xs text-muted-foreground">
          Readiness checked {formatTime(readiness.generated_at)}
        </p>
      ) : null}
    </div>
  )
}

