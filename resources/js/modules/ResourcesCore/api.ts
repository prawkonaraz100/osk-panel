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

  if (!response.ok) {
    let payload: ApiErrorPayload = {}
    try {
      payload = (await response.json()) as ApiErrorPayload
    } catch {
      // Keep the safe generic fallback.
    }

    throw new ApiError(
      response.status,
      payload.error?.code ?? 'REQUEST_FAILED',
      payload.error?.message ?? 'Nie udało się wykonać operacji.',
      payload.error?.fields ?? {},
    )
  }

  if (response.status === 204) {
    return { data: undefined as T, etag: response.headers.get('ETag') }
  }

  return {
    data: (await response.json()) as T,
    etag: response.headers.get('ETag'),
  }
}
