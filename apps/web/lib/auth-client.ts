import { buildApiUrl as buildUrl, parseJson } from "@/lib/api-client"

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
  deviceName?: string
}

export type RegisterPayload = {
  name: string
  email: string
  password: string
  passwordConfirmation: string
  deviceName?: string
}

function buildDeviceName<T extends { deviceName?: string }>(payload: T) {
  return payload.deviceName ? { device_name: payload.deviceName } : {}
}

export async function loginRequest(payload: LoginPayload): Promise<AuthResponse> {
  const response = await fetch(buildUrl("/auth/login"), {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({
      email: payload.email,
      password: payload.password,
      ...buildDeviceName(payload),
    }),
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

export async function registerRequest(payload: RegisterPayload): Promise<AuthResponse> {
  const response = await fetch(buildUrl("/auth/register"), {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({
      name: payload.name,
      email: payload.email,
      password: payload.password,
      password_confirmation: payload.passwordConfirmation,
      ...buildDeviceName(payload),
    }),
  })

  const data = await parseJson(response)

  if (!response.ok) {
    const message =
      (data && typeof data === "object" && "message" in data && typeof data.message === "string"
        ? data.message
        : undefined) ?? "Unable to register with the provided details."

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
  const body = refreshToken ? { refresh_token: refreshToken } : undefined

  const response = await fetch(buildUrl("/auth/logout"), {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
    },
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
