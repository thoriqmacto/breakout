"use client"

import Link from "next/link"
import { usePathname, useRouter } from "next/navigation"
import { useEffect, useState, type ReactNode } from "react"
import {
  Bot,
  Boxes,
  CalendarDays,
  HandCoins,
  FileText,
  LayoutDashboard,
  LineChart,
  PanelLeft,
  PanelRight,
  Briefcase,
  DatabaseBackup,
  SlidersHorizontal,
  Terminal,
} from "lucide-react"
import type { LucideIcon } from "lucide-react"

import { useAuth } from "@/components/auth-provider"
import { Button, buttonVariants } from "@/components/ui/button"
import { cn } from "@/lib/utils"

type NavigationItem = {
  label: string
  href: string
  disabled?: boolean
  icon: LucideIcon
}

const navigation: NavigationItem[] = [
  {
    label: "Overview",
    href: "/dashboard",
    icon: LayoutDashboard,
  },
  {
    label: "Assets",
    href: "/dashboard/assets",
    icon: Boxes,
  },
  {
    label: "Portfolio",
    href: "/dashboard/portfolio",
    icon: Briefcase,
  },
  {
    label: "Trading Days",
    href: "/dashboard/trading-days",
    icon: CalendarDays,
  },
  {
    label: "Broker Summary",
    href: "/dashboard/broker-summary",
    icon: HandCoins,
  },
  {
    label: "Watchlist",
    href: "/dashboard/strategy/watchlist",
    icon: LineChart,
  },
  {
    label: "Strategies",
    href: "/dashboard/strategy",
    icon: SlidersHorizontal,
  },
  {
    label: "Scrapers",
    href: "/dashboard/scrapers",
    icon: Terminal,
  },
  {
    label: "Backup Status",
    href: "/dashboard/backups",
    icon: DatabaseBackup,
  },
  {
    label: "Reports",
    href: "/dashboard/reports",
    icon: FileText,
    disabled: true,
  },
  {
    label: "Automation",
    href: "/dashboard/automation",
    icon: Bot,
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
  const [isHeaderCondensed, setIsHeaderCondensed] = useState(false)

  useEffect(() => {
    if (!loading && !user) {
      router.replace("/login")
    }
  }, [loading, router, user])

  useEffect(() => {
    const handleScroll = () => {
      setIsHeaderCondensed(window.scrollY > 16)
    }

    handleScroll()
    window.addEventListener("scroll", handleScroll)

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
          "sticky top-0 hidden h-screen shrink-0 flex-col border-r bg-sidebar transition-all duration-300 ease-in-out lg:flex",
          isSidebarCollapsed ? "w-16 px-2 py-6" : "w-64 px-6 py-8",
        )}
      >
        <div className="flex h-full flex-col gap-6">
          <div
            className={cn(
              "space-y-2 pb-4 transition-opacity duration-200",
              isSidebarCollapsed ? "pointer-events-none opacity-0" : "opacity-100",
            )}
          >
            <Link
              href="/dashboard"
              className="block text-lg font-semibold"
              tabIndex={isSidebarCollapsed ? -1 : 0}
            >
              Breakout Dashboard
            </Link>
            <p className="text-sm text-muted-foreground">
              Stay up to date with trading performance.
            </p>
          </div>

          <div className="flex flex-1 flex-col overflow-y-auto">
            <nav
              className={cn(
                "flex flex-col gap-1",
                isSidebarCollapsed ? "items-center" : undefined,
              )}
            >
              {navigation.map((item) => {
                const isActive = pathname === item.href
                const Icon = item.icon
                const className = buttonVariants({
                  variant: isActive ? "secondary" : "ghost",
                  size: "sm",
                })

                return (
                  <Link
                    key={item.href}
                    href={item.disabled ? "#" : item.href}
                    className={cn(
                      className,
                      "w-full",
                      isSidebarCollapsed ? "justify-center px-0" : "justify-start",
                      item.disabled ? "pointer-events-none opacity-50" : undefined,
                    )}
                    aria-disabled={item.disabled}
                  >
                    <Icon className="size-4" aria-hidden />
                    {isSidebarCollapsed ? (
                      <span className="sr-only">{item.label}</span>
                    ) : (
                      <span className="truncate">{item.label}</span>
                    )}
                  </Link>
                )
              })}
            </nav>
          </div>
        </div>
      </aside>

      {isSidebarCollapsed ? (
        <Button
          type="button"
          variant="secondary"
          size="icon"
          className="fixed left-4 top-4 z-40 hidden lg:inline-flex"
          onClick={() => setIsSidebarCollapsed(false)}
          aria-label="Expand sidebar"
        >
          <PanelRight className="size-4" />
        </Button>
      ) : null}

      <div className="flex min-h-screen min-w-0 flex-1 flex-col">
        <header
          className={cn(
            "sticky top-0 z-30 flex flex-col gap-4 border-b bg-background/95 px-6 transition-all duration-300 backdrop-blur supports-[backdrop-filter]:bg-background/80 lg:flex-row lg:items-center lg:justify-between",
            isHeaderCondensed ? "py-2 shadow-sm" : "py-4",
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
              <div className="flex min-w-0 flex-col text-left">
                {isHeaderCondensed ? (
                  <p className="text-sm font-medium text-foreground">
                    <span className="block truncate">
                      {user.name}
                      {user.email ? ` · ${user.email}` : ""}
                    </span>
                  </p>
                ) : (
                  <>
                    <p className="text-xs uppercase text-muted-foreground">Signed in as</p>
                    <p className="text-sm font-medium">{user.name}</p>
                    <p className="text-xs text-muted-foreground">{user.email}</p>
                  </>
                )}
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

        <main data-dashboard-main className="flex-1 px-6 py-8">
          {children}
        </main>

        <footer className="border-t bg-background px-6 py-4 text-sm text-muted-foreground">
          © {new Date().getFullYear()} Breakout Technologies. All rights reserved.
        </footer>
      </div>
    </div>
  )
}
