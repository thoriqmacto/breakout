"use client"

import { useCallback, useEffect, useState } from "react"
import { RefreshCw, TriangleAlert } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import {
  BackupSummary,
  CollectionCard,
  DriveHealthCard,
  LocalLocationCard,
  PushOutcome,
  StateGuidance,
  formatTime,
} from "@/components/backup-status"
import { fetchBackupReport, pushToDrive, type PushResult, type Report } from "@/lib/backup-client"

export default function BackupStatusPage() {
  const { accessToken } = useAuth()
  const [report, setReport] = useState<Report | null>(null)
  const [loading, setLoading] = useState(true)
  const [pushing, setPushing] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [outcome, setOutcome] = useState<PushResult | null>(null)

  const load = useCallback(
    async (fresh = false) => {
      if (!accessToken) return
      setLoading(true)
      setError(null)
      try {
        setReport(await fetchBackupReport(accessToken, fresh))
      } catch (reason) {
        setError(reason instanceof Error ? reason.message : "Unable to load backup status")
      } finally {
        setLoading(false)
      }
    },
    [accessToken],
  )

  useEffect(() => {
    void load()
  }, [load])

  // A push returns a freshly computed report, so the table settles without a
  // second round trip and can never show a just-pushed file as still pending.
  const push = useCallback(
    async (symbols?: string[]) => {
      if (!accessToken || pushing) return
      setPushing(true)
      setError(null)
      setOutcome(null)
      try {
        const result = await pushToDrive(accessToken, symbols)
        setOutcome(result)
        setReport(result.report)
      } catch (reason) {
        setError(reason instanceof Error ? reason.message : "The mirror push failed")
      } finally {
        setPushing(false)
      }
    },
    [accessToken, pushing],
  )

  const historical = report?.collections.find((collection) => collection.key === "historical")
  const pending = historical?.counts.pending_push ?? 0

  return (
    <div className="space-y-6 p-6 lg:p-8">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-primary">Data resilience</p>
          <h1 className="text-3xl font-semibold tracking-tight">Backup status</h1>
          <p className="mt-2 text-muted-foreground">
            Compare every historical and broker summary backup against Google Drive by content, and
            push local changes that have not been mirrored yet.
          </p>
        </div>
        <Button variant="outline" onClick={() => void load(true)} disabled={loading || pushing}>
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

      {loading && !report ? (
        <div className="grid gap-4 md:grid-cols-2">
          {[0, 1].map((index) => (
            <Card key={index} className="h-40 animate-pulse bg-muted/40" />
          ))}
        </div>
      ) : null}

      {report ? (
        <>
          <BackupSummary
            collections={report.collections}
            health={report.google_drive}
            pending={pending}
            pushing={pushing}
            onPush={() => void push()}
          />

          {outcome ? <PushOutcome result={outcome} /> : null}

          <div className="grid gap-4 lg:grid-cols-2">
            <LocalLocationCard
              available={report.locations.find((l) => l.key === "local")?.available ?? true}
            />
            <DriveHealthCard health={report.google_drive} />
          </div>

          {report.collections.map((collection) => (
            <CollectionCard
              key={collection.key}
              collection={collection}
              pushing={pushing}
              onPush={(symbol) => void push([symbol])}
            />
          ))}

          <StateGuidance />

          <p className="text-right text-xs text-muted-foreground">
            Last checked {formatTime(report.generated_at)}
          </p>
        </>
      ) : null}
    </div>
  )
}
