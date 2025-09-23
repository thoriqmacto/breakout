"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"

export default function RegisterPage() {
  const router = useRouter()
  const { register, loading, user } = useAuth()
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    if (!loading && user) {
      router.replace("/dashboard")
    }
  }, [loading, router, user])

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const formData = new FormData(event.currentTarget)
    const name = String(formData.get("name") ?? "").trim()
    const email = String(formData.get("email") ?? "").trim()
    const password = String(formData.get("password") ?? "")
    const passwordConfirmation = String(formData.get("password_confirmation") ?? "")
    const deviceName =
      typeof window !== "undefined" ? window.navigator.userAgent : undefined

    setError(null)
    setSubmitting(true)

    try {
      await register({
        name,
        email,
        password,
        passwordConfirmation,
        deviceName,
      })
      router.replace("/dashboard")
    } catch (cause) {
      setError(
        cause instanceof Error
          ? cause.message
          : "We could not create an account with those details."
      )
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/30 px-6 py-16">
      <div className="w-full max-w-xl rounded-xl border bg-background p-8 shadow-sm">
        <div className="space-y-2 text-center">
          <h1 className="text-2xl font-semibold">Create your Breakout account</h1>
          <p className="text-sm text-muted-foreground">
            Register to receive stateless access and refresh tokens for the API.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="mt-8 space-y-5">
          <div className="space-y-2">
            <label htmlFor="name" className="text-sm font-medium text-foreground">
              Full name
            </label>
            <Input
              id="name"
              name="name"
              type="text"
              placeholder="Casey Breaker"
              autoComplete="name"
              required
            />
          </div>

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

          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <label htmlFor="password" className="text-sm font-medium text-foreground">
                Password
              </label>
              <Input
                id="password"
                name="password"
                type="password"
                placeholder="Create a password"
                autoComplete="new-password"
                required
              />
            </div>

            <div className="space-y-2">
              <label
                htmlFor="password_confirmation"
                className="text-sm font-medium text-foreground"
              >
                Confirm password
              </label>
              <Input
                id="password_confirmation"
                name="password_confirmation"
                type="password"
                placeholder="Repeat your password"
                autoComplete="new-password"
                required
              />
            </div>
          </div>

          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>Tokens are issued immediately after signup.</span>
            <Link
              href="/login"
              className="text-sm font-medium text-primary underline-offset-4 hover:underline"
            >
              Already have an account?
            </Link>
          </div>

          {error ? (
            <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
              {error}
            </div>
          ) : null}

          <Button type="submit" className="w-full" disabled={submitting}>
            {submitting ? "Creating account..." : "Register"}
          </Button>
        </form>
      </div>
    </div>
  )
}
