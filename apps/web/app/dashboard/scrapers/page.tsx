"use client"

import { FormEvent, useMemo, useState } from "react"

import { Button } from "@/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Input } from "@/components/ui/input"

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

export default function ScrapersPage() {
  const [baseUrl, setBaseUrl] = useState(DEFAULT_BASE_URL)
  const [endpoint, setEndpoint] = useState("")
  const [query, setQuery] = useState("")
  const [bearerToken, setBearerToken] = useState("")
  const [isLoading, setIsLoading] = useState(false)
  const [responseStatus, setResponseStatus] = useState<string | null>(null)
  const [responseBody, setResponseBody] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

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

    try {
      const response = await fetch(requestUrl, {
        method: "GET",
        headers,
      })

      const statusLabel = `${response.status} ${response.statusText || ""}`.trim()
      setResponseStatus(statusLabel)

      const text = await response.text()

      try {
        const parsed = JSON.parse(text)
        setResponseBody(JSON.stringify(parsed, null, 2))
      } catch {
        setResponseBody(text || "No response body returned.")
      }
    } catch (requestError) {
      if (requestError instanceof Error) {
        setError(requestError.message)
      } else {
        setError("Request failed. Please try again.")
      }
    } finally {
      setIsLoading(false)
    }
  }

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

      <Card>
        <CardHeader>
          <CardTitle>Response</CardTitle>
          <CardDescription>
            The API response will be formatted for readability when possible.
          </CardDescription>
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
    </div>
  )
}
