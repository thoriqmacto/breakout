"use client"

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react"
import { Check, Clipboard } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { BrowserLoginCard } from "@/components/browser-login-card"
import { buildApiUrl, parseJson, type ApiResponse } from "@/lib/api-client"

const DEFAULT_BASE_URL = "https://exodus.stockbit.com"

const normalizeBaseUrl = (value: string) => {
  const trimmed = value.trim()
  const sanitized = trimmed.replace(/\/+$/, "")
  return sanitized || DEFAULT_BASE_URL
}

const normalizeEndpoint = (value: string) => {
  const trimmed = value.trim()
  if (!trimmed) {
    return ""
  }

  return trimmed.startsWith("/") ? trimmed : `/${trimmed}`
}

const normalizeQuery = (value: string) => {
  const trimmed = value.trim()
  if (!trimmed) {
    return ""
  }

  return trimmed.startsWith("?") ? trimmed : `?${trimmed}`
}

type ScraperHistoryEntry = {
  id: number
  base_url: string
  endpoint: string | null
  query: string | null
  bearer_token: string | null
  request_url: string
  curl_command: string
  response_status: string | null
  response_body: string
  created_at: string | null
  updated_at: string | null
}

type SaveHistoryPayload = {
  base_url: string
  endpoint: string | null
  query: string | null
  bearer_token: string | null
  request_url: string
  curl_command: string
  response_status: string | null
  response_body: string
}

export default function ScrapersPage() {
  const { accessToken } = useAuth()
  const [baseUrl, setBaseUrl] = useState(DEFAULT_BASE_URL)
  const [endpoint, setEndpoint] = useState("")
  const [query, setQuery] = useState("")
  const [bearerToken, setBearerToken] = useState("")
  const [isLoading, setIsLoading] = useState(false)
  const [responseStatus, setResponseStatus] = useState<string | null>(null)
  const [responseBody, setResponseBody] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [history, setHistory] = useState<ScraperHistoryEntry[]>([])
  const [historyLoading, setHistoryLoading] = useState(false)
  const [historyError, setHistoryError] = useState<string | null>(null)
  const [copyStatus, setCopyStatus] = useState<"idle" | "copied">("idle")
  const [deletingId, setDeletingId] = useState<number | null>(null)

  const requestUrl = useMemo(() => {
    const normalizedBase = normalizeBaseUrl(baseUrl.trim())
    const normalizedEndpoint = normalizeEndpoint(endpoint)
    const normalizedQuery = normalizeQuery(query)

    return `${normalizedBase}${normalizedEndpoint}${normalizedQuery}`
  }, [baseUrl, endpoint, query])

  const curlCommand = useMemo(() => {
    const headers: string[] = []
    const token = bearerToken.trim()

    if (token) {
      headers.push(`-H "Authorization: Bearer ${token}"`)
    }

    const headerString = headers.length
      ? ` \\\n  ${headers.join(" \\\n  ")}`
      : ""

    return `curl -X GET "${requestUrl}"${headerString}`
  }, [bearerToken, requestUrl])

  const loadHistory = useCallback(async () => {
    if (!accessToken) {
      setHistory([])
      return
    }

    setHistoryLoading(true)
    setHistoryError(null)

    try {
      const response = await fetch(buildApiUrl("/v1/scraper-requests"), {
        method: "GET",
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${accessToken}`,
        },
      })

      const payload = await parseJson<ApiResponse<ScraperHistoryEntry[]>>(response)

      if (!response.ok) {
        const message =
          (payload && "message" in payload && typeof payload.message === "string" && payload.message) ||
          "Unable to load saved commands."
        throw new Error(message)
      }

      if (!payload || payload.status !== "success" || !Array.isArray(payload.data)) {
        throw new Error("Unexpected response when loading saved commands.")
      }

      setHistory(payload.data)
    } catch (historyError) {
      console.error(historyError)
      setHistoryError(historyError instanceof Error ? historyError.message : "Unable to load saved commands.")
    } finally {
      setHistoryLoading(false)
    }
  }, [accessToken])

  useEffect(() => {
    void loadHistory()
  }, [loadHistory])

  useEffect(() => {
    setCopyStatus("idle")
  }, [responseBody])

  const saveHistory = useCallback(
    async (payload: SaveHistoryPayload) => {
      if (!accessToken) {
        return
      }

      try {
        const response = await fetch(buildApiUrl("/v1/scraper-requests"), {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            Authorization: `Bearer ${accessToken}`,
          },
          body: JSON.stringify(payload),
        })

        const data = await parseJson<ApiResponse<ScraperHistoryEntry>>(response)

        if (!response.ok) {
          const message =
            (data && "message" in data && typeof data.message === "string" && data.message) ||
            "Unable to save this request."
          throw new Error(message)
        }

        if (!data || data.status !== "success" || !data.data) {
          throw new Error("Unexpected response when saving the request.")
        }

        setHistory((previous) => {
          const filtered = previous.filter((entry) => entry.id !== data.data.id)
          return [data.data, ...filtered].slice(0, 50)
        })
        setHistoryError(null)
      } catch (saveError) {
        console.error(saveError)
        setHistoryError("Unable to save this request for later.")
      }
    },
    [accessToken],
  )

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    const headers: Record<string, string> = {
      Accept: "application/json",
    }

    if (bearerToken.trim()) {
      headers.Authorization = `Bearer ${bearerToken.trim()}`
    }

    setIsLoading(true)
    setError(null)
    setResponseStatus(null)
    setResponseBody(null)

    const historyBasePayload: SaveHistoryPayload = {
      base_url: normalizeBaseUrl(baseUrl),
      endpoint: endpoint.trim() || null,
      query: query.trim() || null,
      bearer_token: bearerToken.trim() || null,
      request_url: requestUrl,
      curl_command: curlCommand,
      response_status: null,
      response_body: "",
    }

    try {
      const response = await fetch(requestUrl, {
        method: "GET",
        headers,
      })

      const statusLabel = `${response.status} ${response.statusText || ""}`.trim()
      const text = await response.text()

      let formattedBody = text || "No response body returned."

      try {
        const parsed = JSON.parse(text)
        formattedBody = JSON.stringify(parsed, null, 2)
      } catch {
        // Leave as plain text
      }

      setResponseStatus(statusLabel)
      setResponseBody(formattedBody)

      historyBasePayload.response_status = statusLabel || null
      historyBasePayload.response_body = formattedBody

      void saveHistory(historyBasePayload)
    } catch (requestError) {
      const message =
        requestError instanceof Error ? requestError.message : "Request failed. Please try again."
      setError(message)
      setResponseStatus("Request failed")
      setResponseBody(message)

      historyBasePayload.response_status = "Request failed"
      historyBasePayload.response_body = message

      void saveHistory(historyBasePayload)
    } finally {
      setIsLoading(false)
    }
  }

  const handleCopyResponse = useCallback(async () => {
    if (!responseBody) {
      return
    }

    try {
      await navigator.clipboard.writeText(responseBody)
      setCopyStatus("copied")
      setTimeout(() => setCopyStatus("idle"), 2000)
    } catch (copyError) {
      console.error("Failed to copy response", copyError)
    }
  }, [responseBody])

  const handleSelectHistory = useCallback(
    (entry: ScraperHistoryEntry) => {
      setBaseUrl(entry.base_url || DEFAULT_BASE_URL)
      setEndpoint(entry.endpoint ?? "")
      setQuery(entry.query ?? "")
      setBearerToken(entry.bearer_token ?? "")
      setResponseStatus(entry.response_status ?? null)
      setResponseBody(entry.response_body ?? "")
      setError(null)
      setCopyStatus("idle")
    },
    [],
  )

  const handleDeleteHistory = useCallback(
    async (id: number) => {
      if (!accessToken) {
        return
      }

      setDeletingId(id)
      setHistoryError(null)

      try {
        const response = await fetch(buildApiUrl(`/v1/scraper-requests/${id}`), {
          method: "DELETE",
          headers: {
            Accept: "application/json",
            Authorization: `Bearer ${accessToken}`,
          },
        })

        let payload: ApiResponse<unknown> | null = null

        try {
          payload = await parseJson<ApiResponse<unknown>>(response)
        } catch (error) {
          console.error("Failed to parse delete response", error)
        }

        if (!response.ok) {
          const message =
            (payload &&
              typeof payload === "object" &&
              payload !== null &&
              "message" in payload &&
              typeof (payload as { message?: unknown }).message === "string" &&
              (payload as { message: string }).message) ||
            "Unable to delete this saved command."
          throw new Error(message)
        }

        setHistory((previous) => previous.filter((entry) => entry.id !== id))
      } catch (deleteError) {
        console.error(deleteError)
        setHistoryError(
          deleteError instanceof Error
            ? deleteError.message
            : "Unable to delete this saved command.",
        )
      } finally {
        setDeletingId((previous) => (previous === id ? null : previous))
      }
    },
    [accessToken],
  )

  return (
    <div className="flex flex-col gap-6 p-6">
      <Card>
        <CardHeader>
          <CardTitle>Scrapers</CardTitle>
          <CardDescription>
            Try Exodus Stockbit API GET requests directly from your workspace.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form className="space-y-4" onSubmit={handleSubmit}>
            <div className="grid gap-2">
              <label className="text-sm font-medium" htmlFor="base-url">
                Base URL
              </label>
              <Input
                id="base-url"
                value={baseUrl}
                onChange={(event) => setBaseUrl(event.target.value)}
                placeholder={DEFAULT_BASE_URL}
                autoComplete="off"
              />
            </div>

            <div className="grid gap-2">
              <label className="text-sm font-medium" htmlFor="endpoint">
                Endpoint Path
              </label>
              <Input
                id="endpoint"
                value={endpoint}
                onChange={(event) => setEndpoint(event.target.value)}
                placeholder="/v1/your-endpoint"
                autoComplete="off"
              />
            </div>

            <div className="grid gap-2">
              <label className="text-sm font-medium" htmlFor="query">
                Query (optional)
              </label>
              <Input
                id="query"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="symbol=BBRI&limit=10"
                autoComplete="off"
              />
            </div>

            <div className="grid gap-2">
              <label className="text-sm font-medium" htmlFor="bearer-token">
                Bearer Token (optional)
              </label>
              <textarea
                id="bearer-token"
                value={bearerToken}
                onChange={(event) => setBearerToken(event.target.value)}
                placeholder="Paste your token here"
                autoComplete="off"
                spellCheck={false}
                rows={4}
                className="w-full min-h-24 resize-y overflow-auto rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-none focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40"
              />
            </div>

            <div className="grid gap-2">
              <span className="text-sm font-medium">Request URL</span>
              <code className="bg-muted text-xs overflow-x-auto rounded-md border px-3 py-2 font-mono text-left text-foreground/80">
                {requestUrl}
              </code>
            </div>

            <div className="grid gap-2">
              <label className="text-sm font-medium" htmlFor="curl-command">
                Generated Command
              </label>
              <textarea
                id="curl-command"
                value={curlCommand}
                readOnly
                rows={4}
                spellCheck={false}
                className="bg-muted text-xs w-full min-h-24 resize-y overflow-auto rounded-md border px-3 py-2 font-mono text-left text-foreground/80 whitespace-pre-wrap focus-visible:border-ring focus-visible:outline-none focus-visible:ring-ring/50 focus-visible:ring-[3px]"
              />
            </div>

            <div className="flex items-center justify-end">
              <Button type="submit" disabled={isLoading}>
                {isLoading ? "Sending..." : "Send GET Request"}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <BrowserLoginCard />

      <Card>
        <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="space-y-1">
            <CardTitle>Response</CardTitle>
            <CardDescription>The API response will be formatted for readability when possible.</CardDescription>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={handleCopyResponse}
            disabled={!responseBody}
          >
            {copyStatus === "copied" ? <Check className="size-4" /> : <Clipboard className="size-4" />}
            {copyStatus === "copied" ? "Copied" : "Copy"}
          </Button>
        </CardHeader>
        <CardContent className="space-y-4">
          {error ? (
            <p className="text-sm text-destructive">{error}</p>
          ) : null}

          <p className="text-sm text-muted-foreground">
            {isLoading
              ? "Sending request..."
              : responseStatus
                ? `Status: ${responseStatus}`
                : "No request sent yet."}
          </p>

          <pre className="bg-muted text-xs max-h-[560px] overflow-auto rounded-md border px-3 py-3 font-mono text-left text-foreground/80 whitespace-pre-wrap">
            {responseBody ?? (isLoading ? "" : "Awaiting response...")}
          </pre>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Saved Commands</CardTitle>
          <CardDescription>Review previous requests and quickly reload them into the form.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {historyLoading ? (
            <p className="text-sm text-muted-foreground">Loading saved commands...</p>
          ) : historyError ? (
            <p className="text-sm text-destructive">{historyError}</p>
          ) : history.length === 0 ? (
            <p className="text-sm text-muted-foreground">No saved commands yet. Send a request to start building your history.</p>
          ) : (
            <div className="overflow-hidden rounded-md border">
              <table className="w-full table-fixed border-collapse text-sm">
                <thead className="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                  <tr>
                    <th className="w-14 px-3 py-2 text-left font-medium">#</th>
                    <th className="px-3 py-2 text-left font-medium">Request URL</th>
                    <th className="w-40 px-3 py-2 text-left font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {history.map((entry, index) => (
                    <tr key={entry.id} className="border-t bg-background/50 align-top">
                      <td className="px-3 py-2 text-xs font-medium text-muted-foreground">{index + 1}</td>
                      <td className="px-3 py-2">
                        <button
                          type="button"
                          onClick={() => handleSelectHistory(entry)}
                          className="w-full break-words text-left text-sm font-medium text-primary underline-offset-4 hover:underline focus:outline-none focus-visible:rounded focus-visible:ring-2 focus-visible:ring-ring"
                        >
                          {entry.request_url}
                        </button>
                        {entry.created_at ? (
                          <p className="mt-1 text-xs text-muted-foreground">
                            Saved {new Date(entry.created_at).toLocaleString()}
                          </p>
                        ) : null}
                      </td>
                      <td className="px-3 py-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => handleSelectHistory(entry)}
                          >
                            Edit
                          </Button>
                          <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            onClick={() => handleDeleteHistory(entry.id)}
                            disabled={deletingId === entry.id}
                          >
                            {deletingId === entry.id ? "Deleting..." : "Delete"}
                          </Button>
                        </div>
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
