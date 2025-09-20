"use client"

import { useEffect, useState } from "react"
import { useRouter } from "next/navigation"

import { useAuth } from "@/components/auth-provider"
import { Button } from "@/components/ui/button"

export default function LogoutPage() {
  const router = useRouter()
  const { logout } = useAuth()
  const [status, setStatus] = useState<"pending" | "complete">("pending")

  useEffect(() => {
    let isMounted = true

    async function performLogout() {
      try {
        await logout()
      } finally {
        if (isMounted) {
          setStatus("complete")
        }
      }
    }

    void performLogout()

    return () => {
      isMounted = false
    }
  }, [logout])

  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-6 bg-muted/30 px-6 py-16 text-center">
      <div className="space-y-2">
        <h1 className="text-3xl font-semibold">{status === "pending" ? "Signing you out" : "Signed out"}</h1>
        <p className="text-sm text-muted-foreground">
          {status === "pending"
            ? "We are ending your current session."
            : "You can close this tab or sign in again when you are ready."}
        </p>
      </div>
      <Button onClick={() => router.push("/login")} variant="outline">
        Return to login
      </Button>
    </div>
  )
}
