"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { SESSION_EXPIRED_MESSAGE } from "@/lib/auth-client"

export default function LoginPage() {
  const router = useRouter()
  const { login, loading, user, sessionStatus, clearSessionStatus } = useAuth()
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    if (!loading && user) {
      router.replace("/dashboard")
    }
  }, [loading, router, user])

  useEffect(() => {
    if (sessionStatus === "expired") {
      setError(SESSION_EXPIRED_MESSAGE)
      clearSessionStatus()
    }
  }, [clearSessionStatus, sessionStatus])

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const formData = new FormData(event.currentTarget)
    const email = String(formData.get("email") ?? "").trim()
    const password = String(formData.get("password") ?? "")
    const deviceName =
      typeof window !== "undefined" ? window.navigator.userAgent : undefined

    setError(null)
    setSubmitting(true)

    try {
      await login({
        email,
        password,
        deviceName,
      })
      router.replace("/dashboard")
    } catch (cause) {
      setError(
        cause instanceof Error
          ? cause.message
          : "We could not log you in with those credentials."
      )
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/30 px-6 py-16">
      <div className="w-full max-w-md rounded-xl border bg-background p-8 shadow-sm">
        <div className="space-y-2 text-center">
          <h1 className="text-2xl font-semibold">Welcome back</h1>
          <p className="text-sm text-muted-foreground">
            Sign in with your Breakout credentials to continue.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="mt-8 space-y-5">
          <div className="space-y-2">
            <label htmlFor="email" className="text-sm font-medium text-foreground">
              Email address
            </label>
            <Input
              id="email"
              name="email"
              type="email"
              placeholder="you@example.com"
              autoComplete="email"
              required
            />
          </div>

          <div className="space-y-2">
            <label htmlFor="password" className="text-sm font-medium text-foreground">
              Password
            </label>
            <Input
              id="password"
              name="password"
              type="password"
              placeholder="Your password"
              autoComplete="current-password"
              required
            />
          </div>

          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>Use your Breakout API credentials.</span>
            <Link
              href="/register"
              className="text-sm font-medium text-primary underline-offset-4 hover:underline"
            >
              Create account
            </Link>
          </div>

          {error ? (
            <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
              {error}
            </div>
          ) : null}

          <Button type="submit" className="w-full" disabled={submitting}>
            {submitting ? "Signing you in..." : "Log in"}
          </Button>
          <p className="text-center text-xs text-muted-foreground">
            <Link href="/" className="underline-offset-4 hover:underline">
              Return home
            </Link>
          </p>
        </form>
      </div>
    </div>
  )
}
