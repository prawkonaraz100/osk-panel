export type ApiErrorPayload = {
  error?: {
    code?: string
    message?: string
    fields?: Record<string, string[]>
    request_id?: string
  }
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly fields: Record<string, string[]> = {},
  ) {
    super(message)
  }
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

function uuid(): string {
  if (globalThis.crypto?.randomUUID) {
    return globalThis.crypto.randomUUID()
  }

  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
    const random = Math.floor(Math.random() * 16)
    const value = character === 'x' ? random : (random & 0x3) | 0x8
    return value.toString(16)
  })
}

function unauthenticatedLoginUrl(): string | null {
  if (['/login', '/register', '/forgot-password', '/reset-password'].includes(window.location.pathname)) {
    return null
  }

  const returnUrl = window.location.pathname + window.location.search

  return '/login?return_url=' + encodeURIComponent(returnUrl)
}

export async function api<T>(
  path: string,
  init: RequestInit & { idempotent?: boolean } = {},
): Promise<{ data: T; etag: string | null }> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('X-Request-Id', uuid())

  if (init.body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }

  const token = csrfToken()
  if (token) {
    headers.set('X-CSRF-TOKEN', token)
  }

  if (init.idempotent) {
    headers.set('Idempotency-Key', uuid())
  }

  const response = await fetch(path, {
    ...init,
    credentials: 'same-origin',
    headers,
  })
  const rawBody = await response.text()

  if (!response.ok) {
    if (response.status === 401) {
      const loginUrl = unauthenticatedLoginUrl()
      if (loginUrl !== null) {
        window.location.assign(loginUrl)
      }
    }

    let payload: ApiErrorPayload = {}
    if (rawBody.trim() !== '') {
      try {
        payload = JSON.parse(rawBody) as ApiErrorPayload
      } catch {
        // Keep the safe generic fallback.
      }
    }

    throw new ApiError(
      response.status,
      payload.error?.code ?? 'REQUEST_FAILED',
      payload.error?.message ?? 'Nie udało się wykonać operacji.',
      payload.error?.fields ?? {},
    )
  }

  if (rawBody.trim() === '') {
    return { data: undefined as T, etag: response.headers.get('ETag') }
  }

  return {
    data: JSON.parse(rawBody) as T,
    etag: response.headers.get('ETag'),
  }
}
