const API_BASE =
  (process.env.NEXT_PUBLIC_API_URL?.replace(/\/$/, "") ?? "http://localhost:8000/api").replace(/\/$/, "")

const API_BASE_ROOT = (() => {
  try {
    const url = new URL(API_BASE)
    const segments = url.pathname.split("/").filter(Boolean)

    if (segments[segments.length - 1] === "api") {
      segments.pop()
    }

    url.pathname = segments.length ? `/${segments.join("/")}` : "/"
    url.search = ""
    url.hash = ""

    return url.toString().replace(/\/$/, "")
  } catch {
    return API_BASE.replace(/\/api$/, "")
  }
})()

function buildUrl(path: string) {
  return `${API_BASE}${path.startsWith("/") ? path : `/${path}`}`
}

function buildSanctumUrl(path: string) {
  return `${API_BASE_ROOT}${path.startsWith("/") ? path : `/${path}`}`
}

async function parseJson(response: Response) {
  try {
    return await response.json()
  } catch {
    return null
  }
}

export type AuthUser = {
  id: number
  name: string
  email: string
}

export type AuthResponse = {
  user: AuthUser
  access_token: string
  access_expires_at: string
  token_type: string
  expires_in: number
  refresh_token: string
  refresh_expires_at: string | null
}

export type LoginPayload = {
  email: string
  password: string
  remember?: boolean
}

function readXsrfCookie(): string | null {
  if (typeof document === "undefined") {
    return null
  }

  const cookies = document.cookie ? document.cookie.split("; ") : []

  for (const cookie of cookies) {
    if (cookie.startsWith("XSRF-TOKEN=")) {
      const value = cookie.substring("XSRF-TOKEN=".length)

      try {
        return decodeURIComponent(value)
      } catch {
        return value
      }
    }
  }

  return null
}

let xsrfTokenPromise: Promise<string | null> | null = null

async function ensureXsrfToken(): Promise<string | null> {
  if (typeof document === "undefined") {
    return null
  }

  const existing = readXsrfCookie()

  if (existing) {
    return existing
  }

  if (!xsrfTokenPromise) {
    xsrfTokenPromise = fetch(buildSanctumUrl("/sanctum/csrf-cookie"), {
      method: "GET",
      headers: {
        Accept: "application/json",
      },
      credentials: "include",
    })
      .then(() => readXsrfCookie())
      .finally(() => {
        xsrfTokenPromise = null
      })
  }

  return xsrfTokenPromise
}

export async function loginRequest(payload: LoginPayload): Promise<AuthResponse> {
  const xsrfToken = await ensureXsrfToken()

  const response = await fetch(buildUrl("/auth/login"), {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(xsrfToken ? { "X-XSRF-TOKEN": xsrfToken } : {}),
    },
    credentials: "include",
    body: JSON.stringify(payload),
  })

  const data = await parseJson(response)

  if (!response.ok) {
    const message =
      (data && typeof data === "object" && "message" in data && typeof data.message === "string"
        ? data.message
        : undefined) ?? "Unable to log in with the provided credentials."

    throw new Error(message)
  }

  if (!data || typeof data !== "object") {
    throw new Error("Unexpected response from the authentication service.")
  }

  return data as AuthResponse
}

export async function logoutRequest({
  refreshToken,
  accessToken,
}: {
  refreshToken?: string | null
  accessToken?: string | null
}): Promise<void> {
  const xsrfToken = await ensureXsrfToken()

  const body = refreshToken ? { refresh_token: refreshToken } : undefined

  const response = await fetch(buildUrl("/auth/logout"), {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
      ...(xsrfToken ? { "X-XSRF-TOKEN": xsrfToken } : {}),
    },
    credentials: "include",
    body: body ? JSON.stringify(body) : undefined,
  })

  if (!response.ok && response.status !== 401) {
    const data = await parseJson(response)
    const message =
      (data && typeof data === "object" && "message" in data && typeof data.message === "string"
        ? data.message
        : undefined) ?? "Unable to log out at this time."

    throw new Error(message)
  }
}

export async function fetchProfile(accessToken: string): Promise<AuthUser> {
  const response = await fetch(buildUrl("/auth/me"), {
    method: "GET",
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${accessToken}`,
    },
    credentials: "include",
  })

  if (!response.ok) {
    throw new Error("Unable to load profile information.")
  }

  const data = await parseJson(response)

  if (!data || typeof data !== "object" || !("user" in data)) {
    throw new Error("Malformed profile response.")
  }

  const user = (data as { user: AuthUser | null }).user

  if (!user) {
    throw new Error("Profile response did not include a user object.")
  }

  return user
}
