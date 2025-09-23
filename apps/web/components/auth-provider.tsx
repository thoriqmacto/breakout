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
  type AuthResponse,
  type AuthUser,
  type LoginPayload,
  type RegisterPayload,
} from "@/lib/auth-client"

const STORAGE_KEY = "breakout.auth.session"

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

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<AuthSession | null>(null)
  const [loading, setLoading] = useState(true)

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

  const clearSession = useCallback(() => {
    setSession(null)
    persistSession(null)
  }, [persistSession])

  useEffect(() => {
    const stored = readStoredSession()

    if (!stored) {
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
      .catch(() => {
        clearSession()
      })
      .finally(() => setLoading(false))
  }, [clearSession, persistSession])

  const login = useCallback(async (payload: LoginPayload) => {
    const response: AuthResponse = await loginRequest(payload)

    const newSession = buildSession(response)

    persistSession(newSession)
    setSession(newSession)
  }, [persistSession])

  const register = useCallback(async (payload: RegisterPayload) => {
    const response: AuthResponse = await registerRequest(payload)

    const newSession = buildSession(response)

    persistSession(newSession)
    setSession(newSession)
  }, [persistSession])

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
    }
  }, [clearSession, session])

  const value = useMemo<AuthContextValue>(
    () => ({
      user: session?.user ?? null,
      accessToken: session?.accessToken ?? null,
      refreshToken: session?.refreshToken ?? null,
      loading,
      login,
      register,
      logout,
    }),
    [login, loading, logout, register, session]
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
