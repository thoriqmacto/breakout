"use client"

import Link from "next/link"
import { usePathname, useRouter } from "next/navigation"
import { useEffect, useRef, useState, type ReactNode } from "react"
import { PanelLeft, PanelRight } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button, buttonVariants } from "@/components/ui/button"
import { cn } from "@/lib/utils"

const navigation = [
  {
    label: "Overview",
    href: "/dashboard",
  },
  {
    label: "Assets",
    href: "/dashboard/assets",
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
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(false)
  const [isHeaderPinned, setIsHeaderPinned] = useState(false)
  const headerRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!loading && !user) {
      router.replace("/login")
    }
  }, [loading, router, user])

  useEffect(() => {
    const handleScroll = () => {
      const headerHeight = headerRef.current?.offsetHeight ?? 0
      const pinned = window.scrollY > headerHeight
      setIsHeaderPinned((previous) => (previous === pinned ? previous : pinned))
    }

    handleScroll()
    window.addEventListener("scroll", handleScroll, { passive: true })

    return () => {
      window.removeEventListener("scroll", handleScroll)
    }
  }, [])

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
      <aside
        className={cn(
          "hidden flex-col overflow-hidden border-r bg-sidebar transition-all duration-300 ease-in-out lg:flex",
          isSidebarCollapsed
            ? "w-0 border-r-0 px-0 py-0 opacity-0"
            : "w-64 px-6 py-8 opacity-100",
        )}
        aria-hidden={isSidebarCollapsed}
      >
        {!isSidebarCollapsed && (
          <>
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
          </>
        )}
      </aside>

      <div className="flex min-h-screen flex-1 flex-col">
        <header
          ref={headerRef}
          className={cn(
            "flex flex-col gap-4 border-b bg-background px-6 transition-all duration-300 lg:flex-row lg:items-center lg:justify-between",
            isHeaderPinned
              ? "sticky top-0 z-30 bg-background/95 py-2 shadow-sm backdrop-blur"
              : "py-4",
          )}
        >
          <div className="flex w-full flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex items-start gap-3 lg:items-center">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="hidden lg:inline-flex"
                onClick={() => setIsSidebarCollapsed((previous) => !previous)}
                aria-label={isSidebarCollapsed ? "Expand sidebar" : "Collapse sidebar"}
                aria-expanded={!isSidebarCollapsed}
              >
                {isSidebarCollapsed ? <PanelRight className="size-4" /> : <PanelLeft className="size-4" />}
              </Button>
              <div>
                <p className="text-xs uppercase text-muted-foreground">Signed in as</p>
                <p className="text-sm font-medium">{user.name}</p>
                <p className="text-xs text-muted-foreground">{user.email}</p>
              </div>
            </div>

            <div className="hidden items-center gap-3 lg:flex">
              <Button asChild variant="ghost">
                <Link href="/logout">Log out</Link>
              </Button>
            </div>
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

          <div className="flex items-center gap-3 lg:hidden">
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
