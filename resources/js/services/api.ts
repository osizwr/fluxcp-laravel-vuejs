/**
 * The HTTP client every request goes through.
 *
 * Authentication is by session cookie, so requests must send credentials and
 * must carry the CSRF token Laravel sets in the XSRF-TOKEN cookie. Keeping
 * both in one place means no caller can forget either and discover it as a 419
 * in production.
 */

export class ApiError extends Error {
    constructor(
        readonly status: number,
        message: string,
        /** Laravel's field-keyed validation errors, when it sent any. */
        readonly errors: Record<string, string[]> = {},
    ) {
        super(message)
        this.name = 'ApiError'
    }

    /** The first message for a field, for showing beside an input. */
    first(field: string): string | undefined {
        return this.errors[field]?.[0]
    }

    get isValidation(): boolean {
        return this.status === 422
    }

    get isUnauthenticated(): boolean {
        return this.status === 401
    }

    get isForbidden(): boolean {
        return this.status === 403
    }

    get isRateLimited(): boolean {
        return this.status === 429
    }
}

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`))

    return match ? decodeURIComponent(match[2]) : null
}

type Options = {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
    body?: unknown
    query?: Record<string, string | number | boolean | undefined | null>
    signal?: AbortSignal
}

function buildUrl(path: string, query?: Options['query']): string {
    const url = new URL(`/api/${path.replace(/^\/+/, '')}`, window.location.origin)

    for (const [key, value] of Object.entries(query ?? {})) {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, String(value))
        }
    }

    return url.toString()
}

export async function request<T>(path: string, options: Options = {}): Promise<T> {
    const { method = 'GET', body, query, signal } = options

    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json'
    }

    // Laravel accepts the token from this header, which is what lets a
    // JSON request pass CSRF verification.
    const token = readCookie('XSRF-TOKEN')
    if (token) {
        headers['X-XSRF-TOKEN'] = token
    }

    const response = await fetch(buildUrl(path, query), {
        method,
        headers,
        // Without this the session cookie is not sent and every request is a
        // guest request.
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
        signal,
    })

    if (response.status === 204) {
        return undefined as T
    }

    const text = await response.text()
    let payload: unknown = null

    if (text) {
        try {
            payload = JSON.parse(text)
        } catch {
            // A non-JSON body means something upstream failed before the
            // application ran, such as a proxy error page.
            throw new ApiError(response.status, 'The server returned an unexpected response.')
        }
    }

    if (!response.ok) {
        const data = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> }

        throw new ApiError(
            response.status,
            data.message ?? `Request failed with status ${response.status}.`,
            data.errors ?? {},
        )
    }

    return payload as T
}

export const api = {
    get: <T>(path: string, query?: Options['query'], signal?: AbortSignal) =>
        request<T>(path, { method: 'GET', query, signal }),
    post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
    put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
    patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
    delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
}
