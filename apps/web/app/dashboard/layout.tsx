"use client"

import Link from "next/link"
import { usePathname, useRouter } from "next/navigation"
import { useCallback, useEffect, useRef, useState, type ReactNode } from "react"
import { Bot, Boxes, FileText, LayoutDashboard, PanelLeft, PanelRight, Terminal } from "lucide-react"
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
    label: "Scrapers",
    href: "/dashboard/scrapers",
    icon: Terminal,
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
  const [isHeaderPinned, setIsHeaderPinned] = useState(false)
  const [headerHeight, setHeaderHeight] = useState(0)
  const headerRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!loading && !user) {
      router.replace("/login")
    }
  }, [loading, router, user])

  const updateHeaderHeight = useCallback(() => {
    const height = headerRef.current?.offsetHeight ?? 0
    setHeaderHeight((previous) => (previous === height ? previous : height))
  }, [])

  useEffect(() => {
    const handleScroll = () => {
      const height = headerRef.current?.offsetHeight ?? 0
      const pinned = window.scrollY > height

      setIsHeaderPinned((previous) => (previous === pinned ? previous : pinned))
    }

    updateHeaderHeight()
    window.addEventListener("scroll", handleScroll, { passive: true })

    return () => {
      window.removeEventListener("scroll", handleScroll)
    }
  }, [updateHeaderHeight])

  useEffect(() => {
    updateHeaderHeight()
  }, [isHeaderPinned, updateHeaderHeight])

  useEffect(() => {
    window.addEventListener("resize", updateHeaderHeight)

    return () => {
      window.removeEventListener("resize", updateHeaderHeight)
    }
  }, [updateHeaderHeight])

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

          <nav className="flex flex-1 flex-col gap-1">
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
      </aside>

      <div
        className="flex min-h-screen flex-1 flex-col"
        style={{
          ["--dashboard-header-height" as string]: `${headerHeight}px`,
          ["--dashboard-header-offset" as string]: `${
            isHeaderPinned ? headerHeight : 0
          }px`,
        }}
      >
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
              <div className="flex min-w-0 flex-col text-left">
                {isHeaderPinned ? (
                  <p className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                    <span className="uppercase tracking-wide">Signed in as</span>
                    <span className="font-medium text-foreground">{user.name}</span>
                    <span aria-hidden className="text-muted-foreground">
                      ·
                    </span>
                    <span className="text-muted-foreground">{user.email}</span>
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
