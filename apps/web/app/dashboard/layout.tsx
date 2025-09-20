"use client"

import Link from "next/link"
import { usePathname, useRouter } from "next/navigation"
import { useEffect, type ReactNode } from "react"

import { useAuth } from "@/components/auth-provider"
import { Button, buttonVariants } from "@/components/ui/button"

const navigation = [
  {
    label: "Overview",
    href: "/dashboard",
  },
  {
    label: "Reports",
    href: "/dashboard/reports",
    disabled: true,
  },
  {
    label: "Automation",
    href: "/dashboard/automation",
    disabled: true,
  },
]

export default function DashboardLayout({
  children,
}: {
  children: ReactNode
}) {
  const { user, loading } = useAuth()
  const router = useRouter()
  const pathname = usePathname()

  useEffect(() => {
    if (!loading && !user) {
      router.replace("/login")
    }
  }, [loading, router, user])

  if (loading || !user) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-muted/30">
        <div className="size-12 animate-spin rounded-full border-4 border-muted border-t-primary" aria-hidden />
        <p className="text-sm text-muted-foreground">Loading your workspace...</p>
      </div>
    )
  }

  return (
    <div className="flex min-h-screen bg-muted/30">
      <aside className="hidden w-64 border-r bg-sidebar px-6 py-8 lg:block">
        <div className="space-y-2 pb-8">
          <Link href="/dashboard" className="text-lg font-semibold">
            Breakout Dashboard
          </Link>
          <p className="text-sm text-muted-foreground">
            Stay up to date with trading performance.
          </p>
        </div>

        <nav className="space-y-1">
          {navigation.map((item) => {
            const isActive = pathname === item.href
            const className = buttonVariants({
              variant: isActive ? "secondary" : "ghost",
              size: "sm",
            })

            return (
              <Link
                key={item.href}
                href={item.disabled ? "#" : item.href}
                className={`${className} w-full justify-start ${
                  item.disabled ? "pointer-events-none opacity-50" : ""
                }`}
                aria-disabled={item.disabled}
              >
                {item.label}
              </Link>
            )
          })}
        </nav>
      </aside>

      <div className="flex min-h-screen flex-1 flex-col">
        <header className="flex flex-col gap-4 border-b bg-background px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p className="text-xs uppercase text-muted-foreground">Signed in as</p>
            <p className="text-sm font-medium">{user.name}</p>
            <p className="text-xs text-muted-foreground">{user.email}</p>
          </div>

          <nav className="flex flex-wrap items-center gap-2 lg:hidden">
            {navigation.map((item) => {
              const isActive = pathname === item.href
              return (
                <Link
                  key={item.href}
                  href={item.disabled ? "#" : item.href}
                  className={`${buttonVariants({
                    variant: isActive ? "secondary" : "ghost",
                    size: "sm",
                  })} ${item.disabled ? "pointer-events-none opacity-50" : ""}`}
                  aria-disabled={item.disabled}
                >
                  {item.label}
                </Link>
              )
            })}
          </nav>

          <div className="flex items-center gap-3 lg:justify-end">
            <Button asChild variant="ghost">
              <Link href="/logout">Log out</Link>
            </Button>
          </div>
        </header>

        <main className="flex-1 px-6 py-8">{children}</main>

        <footer className="border-t bg-background px-6 py-4 text-sm text-muted-foreground">
          © {new Date().getFullYear()} Breakout Technologies. All rights reserved.
        </footer>
      </div>
    </div>
  )
}
