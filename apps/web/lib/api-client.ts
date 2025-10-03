const API_BASE =
  (process.env.NEXT_PUBLIC_API_URL?.replace(/\/$/, "") ?? "http://localhost:8000/api").replace(/\/$/, "")

export function buildApiUrl(path: string): string {
  return `${API_BASE}${path.startsWith("/") ? path : `/${path}`}`
}

export async function parseJson<T = unknown>(response: Response): Promise<T | null> {
  try {
    return (await response.json()) as T
  } catch {
    return null
  }
}

export type ApiSuccessResponse<T> = {
  status: "success"
  data: T
  message?: string | null
  meta?: unknown
}

export type ApiErrorResponse = {
  status: "error"
  message: string
  errors?: unknown
  meta?: unknown
}

export type ApiResponse<T> = ApiSuccessResponse<T> | ApiErrorResponse
