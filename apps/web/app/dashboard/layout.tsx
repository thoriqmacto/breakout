"use client"

import Link from "next/link"
import { usePathname, useRouter } from "next/navigation"
import { useEffect, type ReactNode } from "react"
import {
  Bot,
  Boxes,
  ChevronDown,
  HandCoins,
  LayoutDashboard,
  Menu,
  LineChart,
  Briefcase,
  DatabaseBackup,
  SlidersHorizontal,
  Target,
  Terminal,
} from "lucide-react"
import type { LucideIcon } from "lucide-react"

import { AutomationAlertBanner } from "@/components/automation/alert-banner"
import { useAuth } from "@/components/auth-provider"
import { buttonVariants } from "@/components/ui/button"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { cn } from "@/lib/utils"

type NavigationItem = {
  label: string
  href: string
  description: string
  disabled?: boolean
  icon: LucideIcon
}

type NavigationGroup = {
  label: string
  items: NavigationItem[]
}

/**
 * Overview stays a plain link: a dropdown holding one destination is a click
 * spent on nothing.
 */
const overview: NavigationItem = {
  label: "Overview",
  href: "/dashboard",
  description: "Portfolio, market and automation at a glance.",
  icon: LayoutDashboard,
}

/**
 * Grouped by the question being asked rather than by the page's machinery:
 * what is the market doing, what am I doing about it, and is the plumbing
 * healthy. Ten flat entries in a sidebar made every one of them equally
 * prominent, which meant scanning the list every time.
 */
const navigationGroups: NavigationGroup[] = [
  {
    label: "Market",
    items: [
      {
        label: "Assets",
        href: "/dashboard/assets",
        description: "Coverage, prices and the trading calendar.",
        icon: Boxes,
      },
      {
        label: "Broker Summary",
        href: "/dashboard/broker-summary",
        description: "Accumulation and distribution by broker.",
        icon: HandCoins,
      },
    ],
  },
  {
    label: "Trading",
    items: [
      {
        label: "Portfolio",
        href: "/dashboard/portfolio",
        description: "Holdings, cash and realised performance.",
        icon: Briefcase,
      },
      {
        label: "Execution",
        href: "/dashboard/execution",
        description: "Planned entries, stops and open positions.",
        icon: Target,
      },
      {
        label: "Watchlist",
        href: "/dashboard/strategy/watchlist",
        description: "What the scan surfaced for tomorrow.",
        icon: LineChart,
      },
      {
        label: "Strategies",
        href: "/dashboard/strategy",
        description: "Rules, parameters and backtests.",
        icon: SlidersHorizontal,
      },
    ],
  },
  {
    label: "Operations",
    items: [
      {
        label: "Automation",
        href: "/dashboard/automation",
        description: "Scheduled jobs and their last outcome.",
        icon: Bot,
      },
      {
        label: "Scrapers",
        href: "/dashboard/scrapers",
        description: "Stockbit session and manual fetches.",
        icon: Terminal,
      },
      {
        label: "Backups & Recovery",
        href: "/dashboard/backups",
        description: "Mirror state and restore points.",
        icon: DatabaseBackup,
      },
    ],
  },
]

/**
 * A group is current when the open page lives inside it, so the trigger can
 * carry the same highlight a selected item would. Longest match wins, because
 * /dashboard/strategy/watchlist is inside /dashboard/strategy.
 */
const isItemActive = (pathname: string, href: string) =>
  pathname === href || pathname.startsWith(`${href}/`)

const activeItemHref = (pathname: string): string | null => {
  const candidates = [overview, ...navigationGroups.flatMap((group) => group.items)]
    .map((item) => item.href)
    .filter((href) => (href === "/dashboard" ? pathname === href : isItemActive(pathname, href)))
    .sort((first, second) => second.length - first.length)

  return candidates[0] ?? null
}

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

  const currentHref = activeItemHref(pathname)

  return (
    <div className="flex min-h-screen flex-col bg-muted/30">
      {/*
        Sticky rather than fixed: it stays put while the page scrolls, which is
        what a table's header row needs above it, without the content having to
        carry a matching top offset that drifts whenever the bar's height
        changes.

        One fixed height (h-14), not the previous condense-on-scroll pair. The
        bar was 88px tall at rest and animated to 56px, which moved the top of
        every table on the first wheel click; 56px throughout is the smaller of
        the two and never moves.
      */}
      <header className="sticky top-0 z-40 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
        <div className="flex h-14 items-center gap-2 px-4 sm:px-6">
          <Link href="/dashboard" className="mr-1 shrink-0 text-sm font-semibold tracking-tight">
            Breakout
          </Link>

          {/*
            Under 640px the four triggers do not fit beside the account menu,
            and letting the row scroll sideways hides whole sections behind a
            gesture nobody thinks to try. One menu holding everything is the
            honest version of the same navigation.
          */}
          <DropdownMenu>
            <DropdownMenuTrigger
              className={cn(
                buttonVariants({ variant: "ghost", size: "sm" }),
                "shrink-0 data-[state=open]:bg-accent sm:hidden",
              )}
            >
              <Menu className="size-4" aria-hidden />
              <span>Menu</span>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" className="w-72">
              <DropdownMenuItem asChild className={currentHref === overview.href ? "bg-accent/60" : undefined}>
                <Link href={overview.href}>
                  <overview.icon className="size-4 text-muted-foreground" aria-hidden />
                  <span className="font-medium">{overview.label}</span>
                </Link>
              </DropdownMenuItem>

              {navigationGroups.map((group) => (
                <div key={group.label}>
                  <DropdownMenuSeparator />
                  <DropdownMenuLabel>{group.label}</DropdownMenuLabel>
                  {group.items.map((item) => {
                    const Icon = item.icon

                    return (
                      <DropdownMenuItem
                        key={item.href}
                        asChild
                        disabled={item.disabled}
                        className={item.href === currentHref ? "bg-accent/60" : undefined}
                      >
                        <Link href={item.disabled ? "#" : item.href}>
                          <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                          <span className="font-medium">{item.label}</span>
                        </Link>
                      </DropdownMenuItem>
                    )
                  })}
                </div>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>

          <nav className="hidden min-w-0 flex-1 items-center gap-1 sm:flex">
            <Link
              href={overview.href}
              className={cn(
                buttonVariants({
                  variant: currentHref === overview.href ? "secondary" : "ghost",
                  size: "sm",
                }),
                "shrink-0",
              )}
            >
              <overview.icon className="size-4" aria-hidden />
              <span>{overview.label}</span>
            </Link>

            {navigationGroups.map((group) => {
              const hasCurrent = group.items.some((item) => item.href === currentHref)

              return (
                <DropdownMenu key={group.label}>
                  <DropdownMenuTrigger
                    className={cn(
                      buttonVariants({ variant: hasCurrent ? "secondary" : "ghost", size: "sm" }),
                      "group shrink-0 data-[state=open]:bg-accent data-[state=open]:text-accent-foreground",
                    )}
                  >
                    {group.label}
                    <ChevronDown
                      className="size-3.5 opacity-60 transition-transform duration-200 group-data-[state=open]:rotate-180"
                      aria-hidden
                    />
                  </DropdownMenuTrigger>

                  <DropdownMenuContent align="start" className="w-72">
                    <DropdownMenuLabel>{group.label}</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {group.items.map((item) => {
                      const Icon = item.icon

                      return (
                        <DropdownMenuItem
                          key={item.href}
                          asChild
                          disabled={item.disabled}
                          className={cn(
                            "items-start py-2",
                            item.href === currentHref ? "bg-accent/60" : undefined,
                          )}
                        >
                          <Link href={item.disabled ? "#" : item.href}>
                            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
                            <span className="flex min-w-0 flex-col">
                              <span className="font-medium">{item.label}</span>
                              <span className="text-xs text-muted-foreground">{item.description}</span>
                            </span>
                          </Link>
                        </DropdownMenuItem>
                      )
                    })}
                  </DropdownMenuContent>
                </DropdownMenu>
              )
            })}
          </nav>

          <DropdownMenu>
            <DropdownMenuTrigger
              className={cn(
                buttonVariants({ variant: "ghost", size: "sm" }),
                "ml-auto shrink-0 data-[state=open]:bg-accent",
              )}
            >
              <span className="hidden max-w-[12rem] truncate sm:inline">{user.name}</span>
              <span className="sm:hidden">Account</span>
              <ChevronDown className="size-3.5 opacity-60" aria-hidden />
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-64">
              <DropdownMenuLabel>Signed in as</DropdownMenuLabel>
              <div className="px-2 pb-1.5">
                <p className="truncate text-sm font-medium">{user.name}</p>
                {user.email ? (
                  <p className="truncate text-xs text-muted-foreground">{user.email}</p>
                ) : null}
              </div>
              <DropdownMenuSeparator />
              <DropdownMenuItem asChild>
                <Link href="/logout">Log out</Link>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </header>

      {/*
        No sidebar, and no max-width: the widest table here asks for 1500px and
        the old 256px rail guaranteed it was scrolled sideways on a 1440px
        screen. The page gutter is the only thing between a table and the
        viewport edge now, and it tightens on small screens where it costs most.
      */}
      <main data-dashboard-main className="w-full flex-1 px-4 py-6 sm:px-6">
        {/*
          The Stockbit token is the single point of failure for every
          scheduled market-data job, and it can only be renewed by a person.
          Surfacing the warning on every authenticated page -- rather than
          only on /dashboard/automation -- is what gives someone the chance
          to act before the 16:00 WIB scrape stands down.
        */}
        <AutomationAlertBanner />
        {children}
      </main>

      <footer className="border-t bg-background px-4 py-4 text-sm text-muted-foreground sm:px-6">
        © {new Date().getFullYear()} Breakout Technologies. All rights reserved.
      </footer>
    </div>
  )
}
