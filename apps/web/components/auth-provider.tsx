"use client"

import {
  createContext,
  type ReactNode,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react"

import {
  fetchProfile,
  loginRequest,
  logoutRequest,
  registerRequest,
  UnauthorizedError,
  type AuthResponse,
  type AuthUser,
  type LoginPayload,
  type RegisterPayload,
} from "@/lib/auth-client"

const STORAGE_KEY = "breakout.auth.session"
const SESSION_STATUS_KEY = "breakout.auth.session-status"

type SessionStatus = "expired"

type AuthSession = {
  user: AuthUser
  accessToken: string
  refreshToken: string
  accessExpiresAt: string
  refreshExpiresAt: string | null
  tokenType: string
}

type AuthContextValue = {
  user: AuthUser | null
  accessToken: string | null
  refreshToken: string | null
  loading: boolean
  login: (payload: LoginPayload) => Promise<void>
  register: (payload: RegisterPayload) => Promise<void>
  logout: () => Promise<void>
  sessionStatus: SessionStatus | null
  clearSessionStatus: () => void
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined)

function buildSession(response: AuthResponse): AuthSession {
  return {
    user: response.user,
    accessToken: response.access_token,
    refreshToken: response.refresh_token,
    accessExpiresAt: response.access_expires_at,
    refreshExpiresAt: response.refresh_expires_at,
    tokenType: response.token_type,
  }
}

function readStoredSession(): AuthSession | null {
  if (typeof window === "undefined") {
    return null
  }

  const raw = window.localStorage.getItem(STORAGE_KEY)

  if (!raw) {
    return null
  }

  try {
    const parsed = JSON.parse(raw) as AuthSession

    if (
      parsed &&
      typeof parsed.accessToken === "string" &&
      typeof parsed.refreshToken === "string" &&
      typeof parsed.tokenType === "string"
    ) {
      return parsed
    }
  } catch (error) {
    console.warn("Unable to parse stored auth session", error)
  }

  return null
}

function readStoredStatus(): SessionStatus | null {
  if (typeof window === "undefined") {
    return null
  }

  const raw = window.localStorage.getItem(SESSION_STATUS_KEY)
  return raw === "expired" ? "expired" : null
}

function isExpired(isoDate: string | null | undefined): boolean {
  if (!isoDate) {
    return false
  }

  const expiresAt = Date.parse(isoDate)
  if (Number.isNaN(expiresAt)) {
    return false
  }

  return expiresAt <= Date.now()
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<AuthSession | null>(null)
  const [loading, setLoading] = useState(true)
  const [sessionStatus, setSessionStatus] = useState<SessionStatus | null>(() =>
    readStoredStatus(),
  )

  const persistSession = useCallback((value: AuthSession | null) => {
    if (typeof window === "undefined") {
      return
    }

    if (value) {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
    } else {
      window.localStorage.removeItem(STORAGE_KEY)
    }
  }, [])

  const persistSessionStatus = useCallback((status: SessionStatus | null) => {
    if (typeof window === "undefined") {
      return
    }

    if (status) {
      window.localStorage.setItem(SESSION_STATUS_KEY, status)
    } else {
      window.localStorage.removeItem(SESSION_STATUS_KEY)
    }
  }, [])

  const clearSession = useCallback(() => {
    setSession(null)
    persistSession(null)
  }, [persistSession])

  const clearSessionStatus = useCallback(() => {
    setSessionStatus(null)
    persistSessionStatus(null)
  }, [persistSessionStatus])

  const markSessionExpired = useCallback(() => {
    setSession(null)
    persistSession(null)
    setSessionStatus("expired")
    persistSessionStatus("expired")
  }, [persistSession, persistSessionStatus])

  useEffect(() => {
    if (!session?.accessExpiresAt) {
      return
    }

    if (isExpired(session.accessExpiresAt)) {
      markSessionExpired()
      return
    }

    const expiresAt = Date.parse(session.accessExpiresAt)
    const delay = expiresAt - Date.now()

    if (Number.isNaN(expiresAt) || delay <= 0) {
      return
    }

    const timeoutId = window.setTimeout(() => {
      markSessionExpired()
    }, delay)

    return () => {
      window.clearTimeout(timeoutId)
    }
  }, [markSessionExpired, session?.accessExpiresAt])

  useEffect(() => {
    const stored = readStoredSession()

    if (!stored) {
      setLoading(false)
      return
    }

    if (isExpired(stored.accessExpiresAt)) {
      markSessionExpired()
      setLoading(false)
      return
    }

    setSession(stored)

    fetchProfile(stored.accessToken)
      .then((user) => {
        setSession((previous) => {
          const next = previous ? { ...previous, user } : { ...stored, user }
          persistSession(next)
          return next
        })
      })
      .catch((error) => {
        if (error instanceof UnauthorizedError) {
          markSessionExpired()
        } else {
          clearSession()
        }
      })
      .finally(() => setLoading(false))
  }, [clearSession, markSessionExpired, persistSession])

  const login = useCallback(async (payload: LoginPayload) => {
    const response: AuthResponse = await loginRequest(payload)

    const newSession = buildSession(response)

    clearSessionStatus()
    persistSession(newSession)
    setSession(newSession)
  }, [clearSessionStatus, persistSession])

  const register = useCallback(async (payload: RegisterPayload) => {
    const response: AuthResponse = await registerRequest(payload)

    const newSession = buildSession(response)

    clearSessionStatus()
    persistSession(newSession)
    setSession(newSession)
  }, [clearSessionStatus, persistSession])

  const logout = useCallback(async () => {
    const current = session

    try {
      await logoutRequest({
        refreshToken: current?.refreshToken,
        accessToken: current?.accessToken,
      })
    } catch (error) {
      console.warn("Failed to log out cleanly", error)
    } finally {
      clearSession()
      clearSessionStatus()
    }
  }, [clearSession, clearSessionStatus, session])

  const value = useMemo<AuthContextValue>(
    () => ({
      user: session?.user ?? null,
      accessToken: session?.accessToken ?? null,
      refreshToken: session?.refreshToken ?? null,
      loading,
      login,
      register,
      logout,
      sessionStatus,
      clearSessionStatus,
    }),
    [clearSessionStatus, login, loading, logout, register, session, sessionStatus]
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error("useAuth must be used within an AuthProvider")
  }

  return context
}
