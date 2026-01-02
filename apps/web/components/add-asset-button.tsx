"use client"

import { FormEvent, useCallback, useMemo, useState } from "react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"
import { cn } from "@/lib/utils"

type AssetRecord = {
  id: number
  symbol: string
  name: string
  lot_size?: number | null
  tick_size?: number | null
  sync_price?: boolean
  sync_profile?: boolean
  sync_broker_summary?: boolean
}

type Props = {
  className?: string
  onCreated?: (asset: AssetRecord) => void
  buttonVariant?: "default" | "outline" | "secondary"
  buttonSize?: "sm" | "default"
}

export function AddAssetButton({
  className,
  onCreated,
  buttonVariant = "default",
  buttonSize = "sm",
}: Props) {
  const { accessToken } = useAuth()
  const [isOpen, setIsOpen] = useState(false)
  const [symbol, setSymbol] = useState("")
  const [name, setName] = useState("")
  const [lotSize, setLotSize] = useState("")
  const [tickSize, setTickSize] = useState("")
  const [saving, setSaving] = useState(false)
  const [status, setStatus] = useState<"idle" | "success" | "error">("idle")
  const [message, setMessage] = useState<string | null>(null)

  const canSubmit = useMemo(
    () => symbol.trim().length > 0 && name.trim().length > 0 && Boolean(accessToken),
    [symbol, name, accessToken],
  )

  const resetForm = useCallback(() => {
    setSymbol("")
    setName("")
    setLotSize("")
    setTickSize("")
  }, [])

  const handleSubmit = useCallback(
    async (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault()
      if (!accessToken) {
        setStatus("error")
        setMessage("Sign in to add assets.")
        return
      }

      setSaving(true)
      setStatus("idle")
      setMessage(null)

      const payload: Record<string, unknown> = {
        symbol: symbol.trim().toUpperCase(),
        name: name.trim(),
      }

      if (lotSize.trim().length > 0) {
        payload.lot_size = Number.parseInt(lotSize, 10)
      }

      if (tickSize.trim().length > 0) {
        payload.tick_size = Number.parseFloat(tickSize)
      }

      try {
        const response = await fetch(buildApiUrl("/v1/assets"), {
          method: "POST",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            Authorization: `Bearer ${accessToken}`,
          },
          body: JSON.stringify(payload),
        })

        const json = await parseJson<ApiResponse<AssetRecord>>(response)

        if (!response.ok || !json || json.status !== "success" || !json.data) {
          const errorMessage =
            (json && "message" in json && json.message) ||
            "Unable to create the asset. Please try again."

          throw new Error(typeof errorMessage === "string" ? errorMessage : "Unable to create the asset.")
        }

        setStatus("success")
        setMessage("Asset added successfully.")
        resetForm()
        setIsOpen(false)
        onCreated?.(json.data)
      } catch (cause) {
        const errorMessage =
          cause instanceof Error && cause.message
            ? cause.message
            : "Unable to create the asset. Please try again."
        setStatus("error")
        setMessage(errorMessage)
      } finally {
        setSaving(false)
      }
    },
    [accessToken, symbol, name, lotSize, tickSize, onCreated, resetForm],
  )

  return (
    <div className={cn("space-y-2", className)}>
      <Button
        type="button"
        variant={buttonVariant}
        size={buttonSize}
        onClick={() => setIsOpen((previous) => !previous)}
        disabled={!accessToken}
        className="gap-2"
      >
        Add New Asset
      </Button>

      {isOpen ? (
        <form
          onSubmit={handleSubmit}
          className="space-y-3 rounded-md border border-dashed border-border/70 bg-muted/30 p-3"
        >
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <label className="text-sm font-medium" htmlFor="new-asset-symbol">
                Symbol
              </label>
              <Input
                id="new-asset-symbol"
                value={symbol}
                onChange={(event) => setSymbol(event.target.value.toUpperCase())}
                placeholder="BBCA"
                required
              />
            </div>
            <div className="space-y-1">
              <label className="text-sm font-medium" htmlFor="new-asset-name">
                Name
              </label>
              <Input
                id="new-asset-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="Bank Central Asia"
                required
              />
            </div>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <label className="text-sm font-medium" htmlFor="new-asset-lot-size">
                Lot size (optional)
              </label>
              <Input
                id="new-asset-lot-size"
                inputMode="numeric"
                value={lotSize}
                onChange={(event) => setLotSize(event.target.value)}
                placeholder="100"
              />
            </div>
            <div className="space-y-1">
              <label className="text-sm font-medium" htmlFor="new-asset-tick-size">
                Tick size (optional)
              </label>
              <Input
                id="new-asset-tick-size"
                inputMode="decimal"
                value={tickSize}
                onChange={(event) => setTickSize(event.target.value)}
                placeholder="1"
              />
            </div>
          </div>

          {message ? (
            <p className={cn("text-sm", status === "error" ? "text-destructive" : "text-emerald-700")}>{message}</p>
          ) : (
            <p className="text-xs text-muted-foreground">
              Provide a symbol and name to register a new asset for syncing and trading.
            </p>
          )}

          <div className="flex flex-wrap items-center gap-2">
            <Button type="submit" size="sm" disabled={!canSubmit || saving} className="gap-2">
              {saving ? "Saving…" : "Save asset"}
            </Button>
            <Button type="button" variant="ghost" size="sm" onClick={() => setIsOpen(false)}>
              Cancel
            </Button>
          </div>
        </form>
      ) : null}
    </div>
  )
}
