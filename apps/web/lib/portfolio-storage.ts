export type PortfolioGroup = {
  id: string
  name: string
  createdAt: string
  updatedAt: string
}

export type PortfolioHolding = {
  id: string
  symbol: string
  name: string
  shares: number
  averageCost: number
  targetPrice: number | null
  notes: string
  groupId: string
  createdAt: string
  updatedAt: string
}

export type PortfolioState = {
  groups: PortfolioGroup[]
  holdings: PortfolioHolding[]
}

export type PortfolioSummary = {
  totalHoldings: number
  totalGroups: number
  primaryCount: number
  secondaryCount: number
  latestChange: string | null
}

const STORAGE_KEY = "breakout.dashboard.portfolio"

const nowIso = () => new Date().toISOString()

const DEFAULT_GROUPS: PortfolioGroup[] = [
  {
    id: "primary",
    name: "Primary",
    createdAt: nowIso(),
    updatedAt: nowIso(),
  },
  {
    id: "secondary",
    name: "Secondary",
    createdAt: nowIso(),
    updatedAt: nowIso(),
  },
]

const DEFAULT_HOLDINGS: PortfolioHolding[] = [
  {
    id: "primary-bbca",
    symbol: "BBCA",
    name: "Bank Central Asia",
    shares: 1200,
    averageCost: 8750,
    targetPrice: 9500,
    notes: "Core financial position with steady growth.",
    groupId: "primary",
    createdAt: nowIso(),
    updatedAt: nowIso(),
  },
  {
    id: "secondary-tlkm",
    symbol: "TLKM",
    name: "Telkom Indonesia",
    shares: 800,
    averageCost: 3900,
    targetPrice: 4400,
    notes: "Cash-flow focus; monitor for rotation.",
    groupId: "secondary",
    createdAt: nowIso(),
    updatedAt: nowIso(),
  },
]

export const getDefaultPortfolioState = (): PortfolioState => ({
  groups: DEFAULT_GROUPS.map((group) => ({ ...group })),
  holdings: DEFAULT_HOLDINGS.map((holding) => ({ ...holding })),
})

const parseNumber = (value: unknown): number | null => {
  if (typeof value === "number") {
    return Number.isFinite(value) ? value : null
  }

  if (typeof value === "string" && value.trim()) {
    const parsed = Number.parseFloat(value)
    return Number.isNaN(parsed) ? null : parsed
  }

  return null
}

const sanitizeGroup = (candidate: unknown): PortfolioGroup | null => {
  if (!candidate || typeof candidate !== "object") {
    return null
  }

  const cast = candidate as Record<string, unknown>
  const id = typeof cast.id === "string" && cast.id.trim() ? cast.id.trim() : null
  const name = typeof cast.name === "string" && cast.name.trim() ? cast.name.trim() : null

  if (!id || !name) {
    return null
  }

  const createdAt = typeof cast.createdAt === "string" ? cast.createdAt : nowIso()
  const updatedAt = typeof cast.updatedAt === "string" ? cast.updatedAt : createdAt

  return {
    id,
    name,
    createdAt,
    updatedAt,
  }
}

const sanitizeHolding = (
  candidate: unknown,
  groups: PortfolioGroup[],
): PortfolioHolding | null => {
  if (!candidate || typeof candidate !== "object") {
    return null
  }

  const cast = candidate as Record<string, unknown>
  const id = typeof cast.id === "string" && cast.id.trim() ? cast.id.trim() : null
  const symbol = typeof cast.symbol === "string" && cast.symbol.trim() ? cast.symbol.trim().toUpperCase() : null
  const name = typeof cast.name === "string" && cast.name.trim() ? cast.name.trim() : null
  const groupId = typeof cast.groupId === "string" && cast.groupId.trim() ? cast.groupId.trim() : null
  const shares = parseNumber(cast.shares)
  const averageCost = parseNumber(cast.averageCost)
  const targetPrice = cast.targetPrice === null ? null : parseNumber(cast.targetPrice)
  const notes = typeof cast.notes === "string" ? cast.notes : ""

  if (!id || !symbol || !name || !groupId || shares === null || averageCost === null) {
    return null
  }

  const hasGroup = groups.some((group) => group.id === groupId)
  if (!hasGroup) {
    return null
  }

  const createdAt = typeof cast.createdAt === "string" ? cast.createdAt : nowIso()
  const updatedAt = typeof cast.updatedAt === "string" ? cast.updatedAt : createdAt

  return {
    id,
    symbol,
    name,
    shares,
    averageCost,
    targetPrice: targetPrice ?? null,
    notes,
    groupId,
    createdAt,
    updatedAt,
  }
}

export const loadPortfolioState = (): PortfolioState => {
  if (typeof window === "undefined") {
    return getDefaultPortfolioState()
  }

  const raw = window.localStorage.getItem(STORAGE_KEY)
  if (!raw) {
    return getDefaultPortfolioState()
  }

  try {
    const parsed = JSON.parse(raw) as Record<string, unknown>
    const parsedGroups = Array.isArray(parsed.groups)
      ? parsed.groups
      : []
    const sanitizedGroups = parsedGroups
      .map((candidate) => sanitizeGroup(candidate))
      .filter((group): group is PortfolioGroup => Boolean(group))

    const groups = sanitizedGroups.length > 0 ? sanitizedGroups : getDefaultPortfolioState().groups

    const parsedHoldings = Array.isArray(parsed.holdings)
      ? parsed.holdings
      : []
    const holdings = parsedHoldings
      .map((candidate) => sanitizeHolding(candidate, groups))
      .filter((holding): holding is PortfolioHolding => Boolean(holding))

    return {
      groups,
      holdings,
    }
  } catch (error) {
    console.error("Unable to read stored portfolio state", error)
    return getDefaultPortfolioState()
  }
}

export const persistPortfolioState = (state: PortfolioState) => {
  if (typeof window === "undefined") {
    return
  }

  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
  } catch (error) {
    console.error("Unable to persist portfolio state", error)
  }
}

const formatRelativeTime = (value: string | null): string | null => {
  if (!value) {
    return null
  }

  const timestamp = Date.parse(value)
  if (Number.isNaN(timestamp)) {
    return null
  }

  const difference = Date.now() - timestamp
  const minutes = Math.round(difference / 1000 / 60)

  if (minutes < 1) return "just now"
  if (minutes === 1) return "1 minute ago"
  if (minutes < 60) return `${minutes} minutes ago`

  const hours = Math.round(minutes / 60)
  if (hours === 1) return "1 hour ago"
  if (hours < 24) return `${hours} hours ago`

  const days = Math.round(hours / 24)
  if (days === 1) return "1 day ago"
  return `${days} days ago`
}

export const summarizePortfolio = (state: PortfolioState): PortfolioSummary => {
  const totalHoldings = state.holdings.length
  const totalGroups = state.groups.length
  const primaryCount = state.holdings.filter((holding) => holding.groupId === "primary").length
  const secondaryCount = state.holdings.filter((holding) => holding.groupId === "secondary").length

  const latestHoldingUpdate = state.holdings.reduce<string | null>((latest, holding) => {
    if (!holding.updatedAt) {
      return latest
    }

    if (!latest || Date.parse(holding.updatedAt) > Date.parse(latest)) {
      return holding.updatedAt
    }

    return latest
  }, null)

  return {
    totalHoldings,
    totalGroups,
    primaryCount,
    secondaryCount,
    latestChange: formatRelativeTime(latestHoldingUpdate),
  }
}

export const upsertGroupName = (groups: PortfolioGroup[], groupId: string, name: string) =>
  groups.map((group) =>
    group.id === groupId
      ? {
          ...group,
          name,
          updatedAt: nowIso(),
        }
      : group,
  )

export const createGroup = (groups: PortfolioGroup[], name: string): PortfolioGroup => ({
  id: crypto.randomUUID(),
  name,
  createdAt: nowIso(),
  updatedAt: nowIso(),
})

export const normalizeCurrency = (value: number) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    minimumFractionDigits: 0,
  }).format(value)
