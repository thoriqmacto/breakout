"use client"

import { useEffect, useMemo, useState } from "react"
import Link from "next/link"

import { AddAssetButton } from "@/components/add-asset-button"
import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"
import { cn } from "@/lib/utils"

type AssetSetting = {
  id: number
  symbol: string
  name: string
  sync_price: boolean
  sync_profile: boolean
  sync_broker_summary: boolean
}

type AssetIndexResponse = AssetSetting[]

type SyncSettingsResponse = {
  assets: AssetSetting[]
}

export default function AssetSettingsPage() {
  const { accessToken } = useAuth()
  const [assets, setAssets] = useState<AssetSetting[]>([])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)

  useEffect(() => {
    if (!accessToken) return

    const controller = new AbortController()
    setLoading(true)
    setError(null)

    fetch(buildApiUrl("/v1/assets"), {
      method: "GET",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${accessToken}`,
      },
      signal: controller.signal,
    })
      .then(async (response) => {
        const payload = await parseJson<ApiResponse<AssetIndexResponse>>(response)

        if (!response.ok || !payload || payload.status !== "success" || !Array.isArray(payload.data)) {
          const message =
            (payload && "message" in payload && payload.message) ||
            "Unable to load assets."
          throw new Error(typeof message === "string" ? message : "Unable to load assets.")
        }

        const normalized = payload.data.map((asset) => ({
          ...asset,
          sync_price: asset.sync_price ?? true,
          sync_profile: asset.sync_profile ?? true,
          sync_broker_summary: asset.sync_broker_summary ?? true,
        }))

        setAssets(normalized)
      })
      .catch((cause) => {
        if ((cause as Error)?.name === "AbortError") return
        const message =
          cause instanceof Error && cause.message ? cause.message : "Something went wrong while loading assets."
        setError(message)
      })
      .finally(() => setLoading(false))

    return () => controller.abort()
  }, [accessToken])

  const sortedAssets = useMemo(
    () => [...assets].sort((a, b) => a.symbol.localeCompare(b.symbol)),
    [assets],
  )

  const toggleFlag = (id: number, key: keyof Pick<AssetSetting, "sync_price" | "sync_profile" | "sync_broker_summary">) => {
    setAssets((previous) =>
      previous.map((asset) =>
        asset.id === id ? { ...asset, [key]: !asset[key] } : asset,
      ),
    )
    setSuccessMessage(null)
  }

  const handleSave = async () => {
    if (!accessToken) {
      setError("Sign in to manage asset settings.")
      return
    }

    setSaving(true)
    setError(null)
    setSuccessMessage(null)

    try {
      const response = await fetch(buildApiUrl("/v1/assets/sync-settings"), {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify({
          assets: assets.map((asset) => ({
            id: asset.id,
            sync_price: asset.sync_price,
            sync_profile: asset.sync_profile,
            sync_broker_summary: asset.sync_broker_summary,
          })),
        }),
      })

      const payload = await parseJson<ApiResponse<SyncSettingsResponse>>(response)

      if (!response.ok || !payload || payload.status !== "success" || !payload.data) {
        const message =
          (payload && "message" in payload && payload.message) ||
          "Unable to save asset settings."
        throw new Error(typeof message === "string" ? message : "Unable to save asset settings.")
      }

      const updated = payload.data.assets ?? []
      if (Array.isArray(updated) && updated.length > 0) {
        setAssets(
          updated.map((asset) => ({
            ...asset,
            sync_price: asset.sync_price ?? true,
            sync_profile: asset.sync_profile ?? true,
            sync_broker_summary: asset.sync_broker_summary ?? true,
          })),
        )
      }

      setSuccessMessage("Settings saved. Only checked sync tasks will run during asset:sync --eod.")
    } catch (cause) {
      const message =
        cause instanceof Error && cause.message ? cause.message : "Unable to save asset settings."
      setError(message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">Asset sync settings</h1>
          <p className="text-sm text-muted-foreground">
            Choose which symbols should sync prices, profiles, and broker summaries when{" "}
            <code className="rounded bg-muted px-1 py-0.5">asset:sync --eod</code> runs.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button asChild variant="outline" size="sm">
            <Link href="/dashboard/assets">Back to metrics</Link>
          </Button>
          <AddAssetButton
            buttonVariant="secondary"
            onCreated={(asset) => {
              setAssets((previous) => {
                const filtered = previous.filter((item) => item.id !== asset.id)
                const next = [...filtered, {
                  id: asset.id,
                  symbol: asset.symbol,
                  name: asset.name,
                  sync_price: asset.sync_price ?? true,
                  sync_profile: asset.sync_profile ?? true,
                  sync_broker_summary: asset.sync_broker_summary ?? true,
                }]
                return next
              })
              setSuccessMessage(null)
            }}
          />
        </div>
      </div>

      <Card>
        <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <CardTitle>Sync options</CardTitle>
            <CardDescription>Uncheck a column to exclude that task for the selected asset.</CardDescription>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {successMessage ? <p className="text-sm text-emerald-700">{successMessage}</p> : null}
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
            <Button type="button" onClick={handleSave} disabled={saving || loading || assets.length === 0}>
              {saving ? "Saving…" : "Save changes"}
            </Button>
          </div>
        </CardHeader>
        <CardContent className="space-y-3">
          {loading ? (
            <p className="text-sm text-muted-foreground">Loading assets…</p>
          ) : assets.length === 0 ? (
            <p className="text-sm text-muted-foreground">No assets found. Add a symbol to begin configuring sync tasks.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-xs uppercase text-muted-foreground">
                    <th className="px-3 py-2 font-medium">Asset</th>
                    <th className="px-3 py-2 font-medium">Price</th>
                    <th className="px-3 py-2 font-medium">Profile</th>
                    <th className="px-3 py-2 font-medium">Broker summary</th>
                  </tr>
                </thead>
                <tbody>
                  {sortedAssets.map((asset) => (
                    <tr key={asset.id} className="border-b">
                      <td className="px-3 py-2 text-sm font-medium">
                        <div>{asset.symbol}</div>
                        <p className="text-xs text-muted-foreground">{asset.name}</p>
                      </td>
                      <td className="px-3 py-2">
                        <CheckboxCell
                          id={`price-${asset.id}`}
                          checked={asset.sync_price}
                          onChange={() => toggleFlag(asset.id, "sync_price")}
                          label="Sync price data"
                        />
                      </td>
                      <td className="px-3 py-2">
                        <CheckboxCell
                          id={`profile-${asset.id}`}
                          checked={asset.sync_profile}
                          onChange={() => toggleFlag(asset.id, "sync_profile")}
                          label="Sync profile data"
                        />
                      </td>
                      <td className="px-3 py-2">
                        <CheckboxCell
                          id={`broker-${asset.id}`}
                          checked={asset.sync_broker_summary}
                          onChange={() => toggleFlag(asset.id, "sync_broker_summary")}
                          label="Sync broker summary"
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

function CheckboxCell({
  id,
  checked,
  onChange,
  label,
}: {
  id: string
  checked: boolean
  onChange: () => void
  label: string
}) {
  return (
    <label
      htmlFor={id}
      className={cn(
        "inline-flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm",
        checked ? "border-primary/40 bg-primary/5" : "border-border bg-background",
      )}
    >
      <input id={id} type="checkbox" className="size-4 accent-primary" checked={checked} onChange={onChange} />
      <span>{label}</span>
    </label>
  )
}
