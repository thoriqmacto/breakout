"use client"

import { useCallback, useEffect, useState } from "react"
import { CheckCircle2, Cloud, HardDrive, RefreshCw, TriangleAlert } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

type FileInfo = { path: string; size: number | null; modified_at: string | null }
type BackupFile = { name: string; state: "synced" | "local_only" | "gdrive_only"; local: FileInfo | null; gdrive: FileInfo | null }
type Collection = { key: string; label: string; counts: { total: number; local: number; gdrive: number; synced: number }; files: BackupFile[] }
type Report = { generated_at: string; locations: { key: string; label: string; available: boolean }[]; collections: Collection[] }

const formatBytes = (bytes: number | null) => bytes === null ? "—" : new Intl.NumberFormat(undefined, { notation: "compact", maximumFractionDigits: 1 }).format(bytes) + "B"
const formatTime = (value: string | null) => value ? new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "—"

export default function BackupStatusPage() {
  const { accessToken } = useAuth()
  const [report, setReport] = useState<Report | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!accessToken) return
    setLoading(true)
    setError(null)
    try {
      const response = await fetch(buildApiUrl("/v1/backup-status"), { headers: { Accept: "application/json", Authorization: `Bearer ${accessToken}` } })
      const payload = await parseJson<ApiResponse<Report>>(response)
      if (!response.ok || !payload || payload.status !== "success") throw new Error(payload && "message" in payload ? payload.message : "Unable to load backup status")
      setReport(payload.data)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Unable to load backup status")
    } finally {
      setLoading(false)
    }
  }, [accessToken])

  useEffect(() => { void load() }, [load])

  return <div className="space-y-6 p-6 lg:p-8">
    <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div><p className="text-sm font-medium text-primary">Data resilience</p><h1 className="text-3xl font-semibold tracking-tight">Backup status</h1><p className="mt-2 text-muted-foreground">Compare every historical and broker summary backup across local storage and Google Drive.</p></div>
      <Button variant="outline" onClick={() => void load()} disabled={loading}><RefreshCw className={loading ? "animate-spin" : ""} /> Refresh</Button>
    </div>

    {error ? <Card className="border-destructive/40"><CardContent className="flex items-center gap-3 py-6 text-destructive"><TriangleAlert className="size-5" />{error}</CardContent></Card> : null}
    {loading && !report ? <div className="grid gap-4 md:grid-cols-2">{[0, 1].map(i => <Card key={i} className="h-40 animate-pulse bg-muted/40" />)}</div> : null}

    {report ? <>
      <div className="grid gap-4 sm:grid-cols-2">
        {report.locations.map(location => <Card key={location.key}><CardContent className="flex items-center gap-4 py-5">{location.key === "local" ? <HardDrive className="size-8 text-primary" /> : <Cloud className="size-8 text-primary" />}<div className="flex-1"><p className="font-semibold">{location.label}</p><p className="text-sm text-muted-foreground">{location.available ? "Connected and scanned" : "Unavailable or not configured"}</p></div><span className={`size-3 rounded-full ${location.available ? "bg-emerald-500" : "bg-amber-500"}`} /></CardContent></Card>)}
      </div>
      {report.collections.map(collection => <Card key={collection.key}>
        <CardHeader><div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><CardTitle>{collection.label}</CardTitle><CardDescription>{collection.counts.total} unique backup files discovered</CardDescription></div><div className="flex flex-wrap gap-2 text-xs"><span className="rounded-full bg-muted px-3 py-1.5">Local {collection.counts.local}</span><span className="rounded-full bg-muted px-3 py-1.5">Drive {collection.counts.gdrive}</span><span className="rounded-full bg-emerald-500/10 px-3 py-1.5 text-emerald-700">Synced {collection.counts.synced}</span></div></div></CardHeader>
        <CardContent><div className="overflow-x-auto rounded-lg border"><table className="w-full min-w-[720px] text-sm"><thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground"><tr><th className="px-4 py-3">File</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Local</th><th className="px-4 py-3">Google Drive</th></tr></thead><tbody className="divide-y">{collection.files.map(file => <tr key={file.name} className="hover:bg-muted/30"><td className="max-w-[280px] truncate px-4 py-3 font-mono text-xs" title={file.name}>{file.name}</td><td className="px-4 py-3">{file.state === "synced" ? <span className="inline-flex items-center gap-1.5 text-emerald-700"><CheckCircle2 className="size-4" /> Both</span> : <span className="inline-flex items-center gap-1.5 text-amber-700"><TriangleAlert className="size-4" /> {file.state === "local_only" ? "Local only" : "Drive only"}</span>}</td><td className="px-4 py-3 text-muted-foreground"><div>{formatBytes(file.local?.size ?? null)}</div><div className="text-xs">{formatTime(file.local?.modified_at ?? null)}</div></td><td className="px-4 py-3 text-muted-foreground"><div>{formatBytes(file.gdrive?.size ?? null)}</div><div className="text-xs">{formatTime(file.gdrive?.modified_at ?? null)}</div></td></tr>)}</tbody></table>{collection.files.length === 0 ? <p className="p-8 text-center text-muted-foreground">No backup files found.</p> : null}</div></CardContent>
      </Card>)}
      <p className="text-right text-xs text-muted-foreground">Last checked {formatTime(report.generated_at)}</p>
    </> : null}
  </div>
}
